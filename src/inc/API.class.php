<?php

class API
{
    private static function updateAgent($QUERY, $agent)
    {
        global $FACTORIES;

        $agent->setLastIp(Util::getIP());
        $agent->setLastAction($QUERY['action']);
        $agent->setLastTime(time());
        $FACTORIES->getAgentFactory()->update($agent);
    }

    private static function checkValues($QUERY, $values)
    {
        foreach ($values as $value) {
            if (!isset($QUERY[$value])) {
                return false;
            }
        }
        return true;
    }

    public static function setBenchmark($QUERY)
    {
        global $FACTORIES, $CONFIG;

        // agent submits benchmark for task
        $task = $FACTORIES::getTaskFactory()->get($_GET["taskId"]);
        if ($task == null) {
            API::sendErrorResponse("bench", "Invalid task ID!");
        }
        $qF = new QueryFilter("token", $QUERY['token'], "=");
        $agent = $FACTORIES::getAgentFactory()->filter(array('filter' => $qF), true);
        $qF1 = new QueryFilter("agentId", $agent->getId(), "=");
        $qF2 = new QueryFilter("taskId", $task->getId(), "=");
        $assignment = $FACTORIES::getAssignmentFactory()->filter(array('filter' => array($qF1, $qF2)), true);
        if ($assignment == null) {
            API::sendErrorResponse("keyspace", "You are not assigned to this task!");
        }

        $benchmarkProgress = floatval($_GET['progress']);
        $benchmarkTotal = floatval($_GET['total']);
        $state = intval($_GET['state']);

        if ($benchmarkProgress <= 0) {
            $agent->setIsActive(0);
            $FACTORIES::getAgentFactory()->update($agent);
            API::sendErrorResponse("bench", "Benchmark didn't measure anything!");
        }
        $keyspace = $task->getKeyspace();
        if ($state == 4 || $state == 5) {
            //the benchmark reached the end of the task
            $benchmarkProgress = $benchmarkTotal;
        }

        if ($state == 6) {
            // the bench ended the right way (aborted)
            // extrapolate from $benchtime to $chunktime
            $benchmarkProgress = $benchmarkProgress / ($benchmarkTotal / $keyspace);
            $benchmarkProgress = round(($benchmarkProgress / $CONFIG->getVal('benchtime')) * $task->getChunkTime());
        } else if ($benchmarkProgress == $benchmarkTotal) {
            $benchmarkProgress = $keyspace;
        } else {
            //problematic
            $benchmarkProgress = 0;
        }

        if ($benchmarkProgress <= 0) {
            API::sendErrorResponse("bench", "Benchmark was not correctly!");
        } else {
            $assignment->setSpeed(0);
            $assignment->setBenchmark($benchmarkProgress);
            $FACTORIES::getAssignmentFactory()->update($assignment);
        }
        API::sendResponse(array("action" => "bench", "respone" => "SUCCESS", "benchmark" => "OK"));
    }

    public static function setKeyspace($QUERY)
    {
        global $FACTORIES;

        // agent submits keyspace size for this task
        $keyspace = floatval($_GET["keyspace"]);
        $task = $FACTORIES::getTaskFactory()->get($QUERY['taskId']);
        if ($task == null) {
            API::sendErrorResponse("keyspace", "Invalid task ID!");
        }
        $qF = new QueryFilter("token", $QUERY['token'], "=");
        $agent = $FACTORIES::getAgentFactory()->filter(array('filter' => $qF), true);
        $qF1 = new QueryFilter("agentId", $agent->getId(), "=");
        $qF2 = new QueryFilter("taskId", $task->getId(), "=");
        $assignment = $FACTORIES::getAssignmentFactory()->filter(array('filter' => array($qF1, $qF2)), true);
        if ($assignment == null) {
            API::sendErrorResponse("keyspace", "You are not assigned to this task!");
        }

        if ($task->getKeyspace() == 0) {
            // keyspace is still required
            $task->setKeyspace($keyspace);
            $FACTORIES::getTaskFactory()->update($task);
        }
        API::sendResponse(array("action" => "keyspace", "respone" => "SUCCESS", "keyspace" => "OK"));
    }

    public static function getChunk($QUERY)
    {
        global $FACTORIES, $CONFIG;

        // assign a correctly sized chunk to agent

        // default: 1.2 (120%) this says that if desired chunk size is X and remaining keyspace is 1.2 * X then
        // it will be assigned as a whole instead of first assigning X and then 0.2 * X (which would be very small
        // and therefore very slow due to lack of GPU utilization)
        $disptolerance = 1.2;

        $task = $FACTORIES::getTaskFactory()->get($QUERY['taskId']);
        if ($task == null) {
            API::sendErrorResponse("chunk", "Invalid task ID!");
        }

        //check if agent is assigned
        $qF = new QueryFilter("token", $QUERY['token'], "=");
        $agent = $FACTORIES::getAgentFactory()->filter(array('filter' => $qF), true);
        $qF1 = new QueryFilter("agentId", $agent->getId(), "=");
        $qF2 = new QueryFilter("taskId", $task->getId(), "=");
        $assignment = $FACTORIES::getAssignmentFactory()->filter(array('filter' => array($qF1, $qF2)), true);
        $qF = new QueryFilter("taskId", $task->getId(), "=");
        $chunks = $FACTORIES::getChunkFactory()->filter(array('filter' => $qF));
        $dispatched = 0;
        foreach ($chunks as $chunk) {
            $dispatched += $chunk->getLength();
        }
        if ($assignment == null) {
            API::sendErrorResponse("chunk", "You are not assigned to this task!");
        } else if ($task->getKeyspace() == 0) {
            API::sendResponse(array("action" => "task", "response" => "SUCCESS", "chunk" => "keyspace_required"));
        } else if ($task->getProgress() == $task->getKeyspace() || $task->getKeyspace() == $dispatched) {
            API::sendResponse(array("action" => "task", "response" => "SUCCESS", "chunk" => "fully_dispatched"));
        } else if ($assignment->getBenchmark() == 0) {
            API::sendResponse(array("action" => "task", "response" => "SUCCESS", "chunk" => "benchmark"));
        }

        $FACTORIES::getAgentFactory()->getDB()->query("START TRANSACTION");
        $timeoutChunk = null;
        $qF1 = new ComparisonFilter("progress", "length", "<");
        $qF2 = new QueryFilter("taskId", $task->getId(), "=");
        $oF = new OrderFilter("skip", "ASC");
        $chunks = $FACTORIES::getChunkFactory()->filter(array('filter' => array($qF1, $qF2), 'order' => $oF));
        foreach ($chunks as $chunk) {
            if (max($chunk->getDispatchTime(), $chunk->getSolvetime()) < time() - $CONFIG->getVal('chunktimeout') && $chunk->getAgentId() != $agent->getId()) {
                $timeoutChunk = $chunk;
                break;
            } else if ($chunk->getAgent == $agent->getId() || $chunk->getState() == 6 || $chunk->getState() == 10) {
                $timeoutChunk = $chunk;
                break;
            }
        }

        $workChunk = null;
        $createnew = false;

        if ($timeoutChunk != null) {
            // we work on an already existing chunk
            $skip = $timeoutChunk->getSkip();
            $length = $timeoutChunk->getLength();
            $progress = $timeoutChunk->getProgess();
            $skip += $progress;
            $length -= $progress;

            if ($length > $agent->getBenchmark() * $disptolerance && $timeoutChunk->getAgentId() != $agent->getId()) {
                $newSkip = $skip + $agent->getBenchmark();
                $newLength = $length - $agent->getBenchmark();
                $chunk = new Chunk(0, $task->getId(), $newSkip, $newLength, $timeoutChunk->getAgentId(), $timeoutChunk->getDispatchTime(), 0, 0, 9, 0, 0);
                $FACTORIES::getChunkFactory()->save($chunk);
                $length = $agent->getBenchmark();
            }

            if ($timeoutChunk->getProgress() == 0) {
                //whole chunk was not started yet
                $timeoutChunk->setAgentId($agent->getId());
                $timeoutChunk->setLength($length);
                $timeoutChunk->setRprogress(0);
                $timeoutChunk->setDispatchTime(time());
                $timeoutChunk->setSolveTime(0);
                $timeoutChunk->setState(0);
                $FACTORIES::getChunkFactory()->update($timeoutChunk);
                $workChunk = $timeoutChunk;
            } else {
                //finish the cut part
                // some of the chunk was complete, cut the complete part to standalone finished chunk
                $timeoutChunk->setLength($timeoutChunk->getProgress());
                $timeoutChunk->setRprogress(10000);
                $timeoutChunk->setState(9);
                $FACTORIES::getChunkFactory()->update($timeoutChunk);
                $createnew = true;
            }
        }
        if ($timeoutChunk == null || $createnew) {
            // we need to create a new chunk
            $remaining = $task->getKeyspace() - $task->getProgress();
            if ($remaining > 0) {
                $length = min($remaining, $agent->getBenchmark());
                if ($remaining / $length <= $disptolerance) {
                    $length = $remaining;
                }

                $start = $task->getProgess();
                $newProgress = $task->getProgress() + $length;
                $task->setProgress($newProgress);
                $FACTORIES::getTaskFactory()->update($task);
                $chunk = new Chunk(0, $task->getId(), $start, $length, $agent->getId(), time(), 0, 0, 0, 0, 0);
                $FACTORIES::getChunkFactory()->save($chunk);
                $workChunk = $chunk;
            }
        }

        //send answer
        API::sendResponse(array("action" => "task", "response" => "SUCCESS", "chunk" => $workChunk->getId(), "skip" => $workChunk->getSkip(), "length" => $workChunk->getLength()));
    }

    public static function sendErrorResponse($action, $msg)
    {
        $ANS = array();
        $ANS['action'] = $action;
        $ANS['response'] = "ERROR";
        $ANS['message'] = $msg;
        header("Content-Type: application/json");
        echo json_encode($ANS, true);
        die();
    }

    public static function checkToken($QUERY)
    {
        global $FACTORIES;

        $qF = new QueryFilter("token", $QUERY['token'], "=");
        $token = $FACTORIES::getAgentFactory()->filter(array('filter' => array($qF)), true);
        if ($token != null) {
            return true;
        }
        return false;
    }

    private static function sendResponse($RESPONSE)
    {
        header("Content-Type: application/json");
        echo json_encode($RESPONSE, true);
        die();
    }

    public static function registerAgent($QUERY)
    {
        global $FACTORIES, $CONFIG;

        //check required values
        if (!API::checkValues($QUERY, array('voucher', 'gpus', 'uid', 'name', 'os'))) {
            API::sendErrorResponse("register", "Invalid registering query!");
        }

        $qF = new QueryFilter("voucher", $QUERY['voucher'], "=");
        $voucher = $FACTORIES::getRegVoucherFactory()->filter(array('filter' => array($qF)), true);
        if ($voucher == null) {
            API::sendErrorResponse("register", "Provided voucher does not exist.");
        }

        $gpu = $QUERY["gpus"];
        $uid = htmlentities($QUERY["uid"], false, "UTF-8");
        $name = htmlentities($QUERY["name"], false, "UTF-8");
        $os = intval($QUERY["os"]);

        //determine if the client has cpu only
        $cpuOnly = 1;
        foreach ($gpu as $card) {
            $card = strtolower($card);
            if ((strpos($card, "amd") !== false) || (strpos($card, "ati ") !== false) || (strpos($card, "radeon") !== false) || strpos($card, "nvidia") !== false) {
                $cpuOnly = 0;
            }
        }

        //create access token & save agent details
        $token = Util::randomString(10);
        $gpu = htmlentities(implode("\n", $gpu), false, "UTF-8");
        $agent = new Agent(0, $name, $uid, $os, $gpu, "", "", $CONFIG->getVal('agenttimeout'), "", 1, 0, $token, "register", time(), Util::getIP(), 0, $cpuOnly);
        $FACTORIES::getRegVoucherFactory()->delete($voucher);
        if ($FACTORIES::getAgentFactory()->save($agent)) {
            API::sendResponse(array("action" => "register", "response" => "SUCCESS", "token" => $token));
        } else {
            API::sendErrorResponse("register", "Could not register you to server.");
        }
    }

    public static function loginAgent($QUERY)
    {
        global $FACTORIES, $CONFIG;

        if (!API::checkValues($QUERY, array('token'))) {
            API::sendErrorResponse("login", "Invalid login query!");
        }

        // login to master server with previously provided token
        $qF = new QueryFilter("token", $QUERY['token'], "=");
        $agent = $FACTORIES::getAgentFactory()->filter(array('filter' => array($qF)), true);
        if ($agent == null) {
            // token was not found
            API::sendErrorResponse("login", "Unknown token, register again!");
        }
        API::updateAgent($QUERY, $agent);
        API::sendResponse(array("action" => "login", "response" => "SUCCESS", "timeout" => $CONFIG->getVal("agenttimeout")));
    }

    public static function checkClientUpdate($QUERY)
    {
        global $SCRIPTVERSION, $SCRIPTNAME;

        // check if provided hash is the same as script and send file contents if not
        if (!API::checkValues($QUERY, array('version'))) {
            API::sendErrorResponse('update', 'Version value missing!');
        }

        $version = $QUERY['version'];

        if ($version != $SCRIPTVERSION) {
            API::sendResponse(array('action' => 'update', 'response' => 'SUCCESS', 'version' => 'NEW', 'data' => file_get_contents(dirname(__FILE__) . "/../static/$SCRIPTNAME")));
        } else {
            API::sendResponse(array('action' => 'update', 'response' => 'SUCCESS', 'version' => 'OK'));
        }
    }

    public static function downloadApp($QUERY)
    {
        global $FACTORIES;

        if (!API::checkValues($QUERY, array('token', 'type'))) {
            API::sendErrorResponse("download", "Invalid download query!");
        }
        $qF = new QueryFilter("token", $QUERY['token'], "=");
        $agent = $FACTORIES::getAgentFactory()->filter(array('filter' => array($qF)), true);

        // provide agent with requested download
        switch ($QUERY['type']) {
            case "7zr":
                // downloading 7zip
                $filename = "7zr" . ($agent->getOs() == 1) ? ".exe" : "";
                header_remove("Content-Type");
                header('Content-Type: application/octet-stream');
                header("Content-Disposition: attachment; filename=\"" . $filename . "\"");
                echo file_get_contents("static/" . $filename);
                die();
            case "hashcat":
                if (API::checkValues($QUERY, array('version'))) {
                    API::sendErrorResponse("download", "Invalid download (hashcat) query!");
                }
                $oF = new OrderFilter("time", "DESC LIMIT 1");
                $hashcat = $FACTORIES::getHashcatReleaseFactory()->filter(array('order' => array($oF)), true);
                if ($hashcat == null) {
                    API::sendErrorResponse("download", "No Hashcat release available!");
                }

                $postfix = array("bin", "exe");
                $executable = "hashcat64" . $postfix[$agent->getOs()];

                if ($QUERY['version'] == $hashcat->getVersion() && (!isset($QUERY['force']) || $QUERY['force'] != '1')) {
                    API::sendResponse(array("action" => 'download', 'response' => 'SUCCESS', 'version' => 'OK', 'executable' => $executable));
                }

                $url = $hashcat->getUrl();
                $files = explode("\n", str_replace(" ", "\n", $hashcat->getCommonFiles()));
                $files[] = $executable;
                $rootdir = $hashcat->getRootdir();

                $agent->setHcVersion($hashcat->getVersion());
                $FACTORIES::getAgentFactory()->update($agent);
                API::sendResponse(array('action' => 'download', 'response' => 'SUCCESS', 'version' => 'NEW', 'url' => $url, 'files' => $files, 'rootdir' => $rootdir, 'executable' => $executable));
                break;
            default:
                API::sendErrorResponse('download', "Unknown download type!");
        }
    }

    public static function agentError($QUERY)
    {
        global $FACTORIES;

        //check required values
        if (!API::checkValues($QUERY, array('token', 'task', 'message'))) {
            API::sendErrorResponse("error", "Invalid error query!");
        }

        //check agent and task
        $qF = new QueryFilter("token", $QUERY['token'], "=");
        $agent = $FACTORIES::getAgentFactory()->filter(array('filter' => array($qF)), true);
        $task = $FACTORIES::getTaskFactory()->get($QUERY['task']);
        if ($task == null) {
            API::sendErrorResponse("error", "Invalid task!");
        }

        //check assignment
        $qF1 = new QueryFilter("agentId", $agent->getId(), "=");
        $qF2 = new QueryFilter("taskId", $task->getId(), "=");
        $assignment = $FACTORIES::getAssignmentFactory()->filter(array('filter' => array($qF1, $qF2)), true);
        if ($assignment == null) {
            API::sendErrorResponse("error", "You are not assigned to this task!");
        }

        //save error message
        $error = new AgentError(0, $agent->getId(), $task->getId(), time(), $QUERY['message']);
        $FACTORIES::getAgentErrorFactory()->save($error);

        if ($agent->getIgnoreErrors() == 0) {
            //deactivate agent
            $agent->setIsActive(0);
            $FACTORIES::getAgentFactory()->update($agent);
        }
        API::sendResponse(array('action' => 'error', 'response' => 'SUCCESS'));
    }

    public static function getFile($QUERY)
    {
        global $FACTORIES;

        //check required values
        if (!API::checkValues($QUERY, array('token', 'task', 'filename'))) {
            API::sendErrorResponse("file", "Invalid file query!");
        }

        // let agent download adjacent files
        $task = $FACTORIES::getTaskFactory()->get($QUERY['task']);
        if ($task == null) {
            API::sendErrorResponse('file', "Invalid task!");
        }

        $filename = $QUERY['filename'];
        $qF = new QueryFilter("filename", $filename, "=");
        $file = $FACTORIES::getFileFactory()->filter(array('filter' => array($qF)), true);
        if ($file == null) {
            API::sendErrorResponse('file', "Invalid file!");
        }

        $qF = new QueryFilter("token", $QUERY['token'], "=");
        $agent = $FACTORIES::getAgentFactory()->filter(array('filter' => array($qF)), true);

        $qF1 = new QueryFilter("taskId", $task->getId(), "=");
        $qF2 = new QueryFilter("agentId", $agent->getId(), "=");
        $assignment = $FACTORIES::getAssignmentFactory()->filter(array('filter' => array($qF1, $qF2)), true);
        if ($assignment == null) {
            API::sendErrorResponse('file', "Client is not assigned to this task!");
        }

        $qF1 = new QueryFilter("taskId", $task->getId(), "=");
        $qF2 = new QueryFilter("fileId", $file->getId(), "=");
        $taskFile = $FACTORIES::getTaskFileFactory()->filter(array('filter' => array($qF1, $qF2)), true);
        if ($taskFile == null) {
            API::sendErrorResponse('file', "This files is not used for the specified task!");
        }

        if ($agent->getIsTrusted() < $file->getSecret()) {
            API::sendErrorResponse('file', "You have no access to get this file!");
        }
        API::sendResponse(array('action' => 'file', 'response' => 'SUCCESS', 'url' => 'get.php?file=' . $file->getId() . "&token=" . $agent->getToken()));
    }

    public static function getHashes($QUERY)
    {
        global $FACTORIES;

        //check required values
        if (!API::checkValues($QUERY, array('token', 'hashlist'))) {
            API::sendErrorResponse("hashes", "Invalid hashes query!");
        }

        $hashlist = $FACTORIES::getHashlistFactory()->get($QUERY['hashlist']);
        if ($hashlist == null) {
            API::sendErrorResponse('hashes', "Invalid hashlist!");
        }

        $qF = new QueryFilter("token", $QUERY['token'], "=");
        $agent = $FACTORIES::getAgentFactory()->filter(array('filter' => array($qF)), true);
        if ($agent == null) {
            API::sendErrorResponse('hashes', "Invalid agent!");
        }

        $qF = new QueryFilter("agentId", $agent->getId(), "=");
        $assignment = $FACTORIES::getAssignmentFactory()->filter(array('filter' => array($qF)), true);
        if ($assignment == null) {
            API::sendErrorResponse('hashes', "Agent is not assigned to a task!");
        }

        $task = $FACTORIES::getTaskFactory()->get($assignment->getTaskId());
        if ($task == null) {
            API::sendErrorResponse('hashes', "Assignment contains invalid task!");
        }

        if ($task->getHashlistId() != $hashlist->getId()) {
            API::sendErrorResponse('hashes', "This hashlist is not used for the assigned task!");
        } else if ($agent->getIsTrusted() < $hashlist->getSecret()) {
            API::sendErrorResponse('hashes', "You have not access to this hashlist!");
        }
        $LINEDELIM = "\n";
        if ($agent->getOs() == 1) {
            $LINEDELIM = "\r\n";
        }

        $hashlists = array();
        $format = $hashlist->getFormat();
        if ($hashlist->getFormat() == 3) {
            //we have a superhashlist
            $qF = new QueryFilter("superHashlistId", $hashlist->getId(), "=");
            $lists = $FACTORIES->getSuperHashlistHashlistFactory()->filter(array('filter' => array($qF)));
            foreach ($lists as $list) {
                $hl = $FACTORIES::getHashlistFactory()->get($list->getHashlistId());
                if ($hl->getSecret() > $agent->getIsTrusted()) {
                    continue;
                }
                $hashlists[] = $list->getHashlistId();
            }
        } else {
            $hashlists[] = $hashlist->getId();
        }

        if (sizeof($hashlists) == 0) {
            API::sendErrorResponse('hashes', "No hashlists selected!");
        }
        $count = 0;
        switch ($format) {
            case 0:
                header_remove("Content-Type");
                header('Content-Type: text/plain');
                foreach ($hashlists as $list) {
                    $limit = 0;
                    $size = 50000;
                    do {
                        $oF = new OrderFilter("hashId", "ASC LIMIT $limit,$size");
                        $qF1 = new QueryFilter("hashlistId", $list->getId(), "=");
                        $qF2 = new QueryFilter("plaintext", null, "=");                         $current = $FACTORIES::getHashFactory()->filter(array('filter' => array($qF1, $qF2), 'order' => array($oF)));

                        $output = "";
                        $count += sizeof($current);
                        foreach ($current as $entry) {
                            $output .= $entry->getHash();
                            if (strlen($entry->getSalt()) > 0) {
                                $output .= $list->getSaltSeparator() . $entry->getSalt();
                            }
                            $output .= $LINEDELIM;
                        }
                        echo $output;

                        $limit += $size;
                    } while (sizeof($current) > 0);
                }
                break;
            case 1:
            case 2:
                header_remove("Content-Type");
                header('Content-Type: application/octet-stream');
                foreach ($hashlists as $list) {
                    $qF1 = new QueryFilter("hashlistId", $list->getId(), "=");
                    $qF2 = new QueryFilter("plaintext", "null", "=");
                    $current = $FACTORIES::getHashBinaryFactory()->filter(array('filter' => array($qF1, $qF2)));
                    $count += sizeof($current);
                    $output = "";
                    foreach ($current as $entry) {
                        $output .= $entry->getHash();
                    }
                    echo $output;
                }
                break;
        }

        //update that the agent has downloaded the hashlist
        foreach ($hashlists as $list) {
            $qF1 = new QueryFilter("agentId", $agent->getId(), "=");
            $qF2 = new QueryFilter("hashlistId", $list->getId(), "=");
            $check = $FACTORIES::getHashlistAgentFactory()->filter(array('filter' => array($qF1, $qF2)), true);
            if ($check == null) {
                $downloaded = new HashlistAgent(0, $list->getId(), $agent->getId());
                $FACTORIES::getHashlistAgentFactory()->save($downloaded);
            }
        }

        if ($count == 0) {
            API::sendErrorResponse('hashes', "No hashes are available to crack!");
        }
    }

    public static function getTask($QUERY)
    {
        global $FACTORIES;

        $qF = new QueryFilter("token", $QUERY['token'], "=");
        $agent = $FACTORIES::getAgentFactory()->filter(array('filter' => array($qF)), true);
        if ($agent == null) {
            API::sendErrorResponse('task', "Invalid token!");
        } else if ($agent->getIsActive() == 0) {
            API::sendResponse(array('action' => 'task', 'response' => 'SUCCESS', 'task' => 'NONE'));
        }

        $qF = new QueryFilter("agentId", $agent->getId(), "=");
        $assignment = $FACTORIES::getAssignmentFactory()->filter(array('filter' => array($qF)), true);
        $assignedTask = null;
        if ($assignment == null) {
            //search which task we should assign to the agent
            $nextTask = Util::getNextTask($agent);
            $assignment = new Assignment(0, $nextTask->getId(), $agent->getId(), 0, $nextTask->getAutoadjust(), 0);
            $FACTORIES::getAssignmentFactory()->save($assignment);
            $assignedTask = $nextTask;
        } else {
            //check if the agent is assigned to the correct task, if not assign him the right one
            $task = $FACTORIES::getTaskFactory()->get($assignment->getTaskId());
            $finished = false;

            //check if the task is finished
            if ($task->getKeyspace() == $task->getProgress() && $task->getKeyspace() != 0) {
                //task is finished
                $task->setPriority(0);
                $FACTORIES::getTaskFactory()->update($task);
                $finished = true;
            }

            $highPriorityTask = Util::getNextTask($agent);
            if ($highPriorityTask != null) {
                //there is a more important task
                $FACTORIES::getAssignmentFactory()->delete($assignment);
                $assignment = new Assignment(0, $highPriorityTask->getId(), $agent->getId(), 0, $highPriorityTask->getAutoadjust(), 0);
                $FACTORIES::getAssignmentFactory()->save($assignment);
                $assignedTask = $highPriorityTask;
            } else {
                if (!$finished) {
                    $assignedTask = $task;
                }
            }
        }

        if ($assignedTask == null) {
            //no task available
            API::sendResponse(array('action' => 'task', 'response' => 'SUCCESS', 'task' => 'NONE'));
        }

        $qF = new QueryFilter("taskId", $assignedTask->getId(), "=");
        $jF = new JoinFilter($FACTORIES::getFileFactory(), "fileId", "fileId");
        $joinedFiles = $FACTORIES::getTaskFileFactory()->filter(array('join' => $jF, 'filter' => $qF));
        $files = array();
        for ($x = 0; $x < sizeof($joinedFiles['File']); $x++) {
            $files[] = $joinedFiles['File'][$x]->getId();
        }

        API::sendResponse(array(
                'action' => 'task',
                'response' => 'SUCCESS',
                'task' => $assignedTask->getId(),
                'wait' => $agent->getWait(),
                'attackcmd' => $assignedTask->getAttackCmd(),
                'cmdpars' => $agent->getCmdPars() . " --hash-type=" . $assignedTask->getHashTypeId(),
                'hashlist' => $assignedTask->getHashlistId(),
                'bench' => 'new', //TODO: here we should tell him new or continue depending if he was already worked on this hashlist or not
                'statustimer' => $assignedTask->getStatusTimer(),
                'files' => array($files)
            )
        );
    }

    //TODO Work in Progress
    //TODO Handle the case where an agent needs reassignment
    public static function solve($QUERY)
    {
        global $FACTORIES;
        global $CONFIG;

        // upload cracked hashes to server
        $cid = intval($QUERY["chunk"]);
        $keyspaceProgress = floatval($QUERY["curku"]);            //TODO Rename this in API
        $normalizedProgress = floatval($QUERY["progress"]);      //Normalized between 1-10k
        $normalizedTotal = floatval($QUERY["total"]); //TODO Not sure what this variable does
        $speed = floatval($QUERY["speed"]);
        $state = intval($QUERY["state"]);     //Util::getStaticArray($states, $state)
        $action = $QUERY["action"];
        $token = $QUERY["token"];

        /**
         * This part sends a lot of DB-Requests. It may need to be optimized in the future.
         */
        $chunk = $FACTORIES::getChunkFactory()->get($cid);
        if ($chunk == null) {
            API::sendErrorResponse($action, "Invalid chunk id " . $cid);
        }

        $qF = new QueryFilter("token", $token, "=");
        $agent = $FACTORIES::getChunkFactory()->filter(array('filter' => $qF), true);
        if ($agent == null) {
            API::sendErrorResponse($action, "Invalid agent token" . $token);
        }
        if ($chunk->getAgentId() != $agent->getID()) {
            API::sendErrorResponse($action, "You are not assigned to this chunk");
        }

        $task = $FACTORIES::getTaskFactory()->get($chunk->getTaskId());
        if ($task == null) {
            API::sendErrorResponse($action, "No task exists for the given chunk");
        }

        $hashList = $FACTORIES::getHashlistFactory()->get($task->getHashlistId());
        if ($hashList->getSecret() > $agent->getIsTrusted()) {
            API::sendErrorResponse($action, "Unknown Error. The API does not trust you with more information");
        }
        if ($hashList == null) {    //There are preconfigured task with hashlistID == null, but a solving task should never be preconfigured
            API::sendErrorResponse($action, "The given task does not have a corresponding hashList");
        }

        $taskFilter = new QueryFilter("taskId", $task->getID(), "=");
        $agentFilter = new QueryFilter("agentId", $agent->getID(), "=");
        $assignment = $FACTORIES::getAssignmentFactory()->filter(array("filter" => array($taskFilter, $agentFilter)), true);
        if ($assignment == null) {
            API::sendErrorResponse($action, "No assignment exists for your chunk");
        }
        // agent is assigned to this chunk (not necessarily task!)
        // it can be already assigned to other task, but is still computing this chunk until it realizes it
        $skip = $chunk->getSkip();
        $length = $chunk->getLength();
        $agentID = $agent->getID();
        $taskID = $task->getID();
        $hashListID = $hashList->getID();
        $format = $hashList->getFormat();

        /** Progressparsing + checks */
        // strip the offset to get the real progress
        $subtr = ($skip * $normalizedTotal) / ($skip + $length);
        $normalizedProgress -= $subtr;
        $normalizedTotal -= $subtr;
        if ($keyspaceProgress > 0) {
            $keyspaceProgress -= $skip;
        }

        // workaround for hashcat overshooting its curku (keyspaceprogress) boundaries sometimes
        if ($state == 4) {
            $normalizedProgress = $normalizedTotal;
        }

        if ($normalizedProgress > $normalizedTotal) {
            API::sendErrorResponse($action, "You submitted bad progress details.");
        }

        /** newline checks */
        if ($agent->getOs() == 1) {
            $newline = "\n";
        } else {
            $newline = "\r\n";
        }

        // workaround for hashcat not sending correct final curku(keyspaceprogress) =skip+len when done with chunk
        if ($normalizedProgress == $normalizedTotal) {
            $keyspaceProgress = $length;
        }

        /**
         * Update progress inside chunk. relativeChunkProgress is between 1 and 10k
         */
        if ($keyspaceProgress >= 0 && $keyspaceProgress <= $length) {
            if ($normalizedProgress == $normalizedTotal) {
                $relativeChunkProgress = 10000;
            } else {
                $relativeChunkProgress = round(($normalizedProgress / $normalizedTotal) * 10000);
                // protection against rounding errors
                if ($normalizedProgress < $normalizedTotal && $relativeChunkProgress == 10000) {
                    $relativeChunkProgress--;
                }
                if ($normalizedProgress > 0 && $relativeChunkProgress == 0) {
                    $relativeChunkProgress++;
                }
            }
            // update progress inside a chunk and chunk cache
            $chunk = $FACTORIES::getChunkFactory()->get($cid);
            $chunk->setRprogress($relativeChunkProgress);
            $chunk->setProgress($keyspaceProgress);
            $chunk->setSolveTime(time());
            $chunk->setState($state);
            $FACTORIES::getChunkFactory()->update($chunk);
            //TODO Not sure what this does
            file_put_contents("server_solve.txt", var_export($_GET, true) . var_export($_POST, true) . "\n----------------------------------------\n", FILE_APPEND);
        }
        // handle superhashlist
        if ($format == 3) {     //TODO Fixme don't compare with 3
            $superhash = true;
        } else {
            $superhash = false;
        }

        /**
         * TODO No clue what the section below does FIXME
         */
        $hlistar = array();
        $hlistarzap = array();
        if ($superhash) {
            $res = $DB->query("SELECT hashlists.id,hashlists.format,hashlists.secret FROM superhashlists JOIN hashlists ON superhashlists.hashList=hashlists.id WHERE superhashlists.id=$hashListID");
            while ($line = $res->fetch()) {
                $format = $line["format"];
                $hlistar[] = $line["id"];
                if ($line["secret"] <= $agent->getTrusted()) {
                    $hlistarzap[] = $line["id"];
                }
            }
        } else {
            $hlistar[] = $hashListID;
            $hlistarzap[] = $hashListID;
        }

        // create two lists:
        // list of all hashlists in this superhashlist
        $hlisty = implode(",", $hlistar);
        // list of those hashlists in superhashlist this agent is allowed to read
        $hlistyzap = implode(",", $hlistarzap);


        // reset values
        $cracked = 0;
        $skipped = 0;
        $errors = 0;

        // process solved hashes, should there be any
        $rawdata = file_get_contents("php://input");
        if (strlen($rawdata) > 0) {
            // there is some uploaded text (cracked hashes)
            $data = explode($newline, $rawdata);
            if (count($data) > 1) {
                // there is more then one line
                // (even for one hash, there is $newline at the end so that makes it two lines)
                $tbls = array(
                    "hashes",
                    "hashes_binary",
                    "hashes_binary"
                );
                $tbl = $tbls[$format];

                // create temporary table to cache cracking stats
                $DB->query("CREATE TEMPORARY TABLE tmphlcracks (hashList INT NOT NULL, cracked INT NOT NULL DEFAULT 0, zaps BIT(1) DEFAULT 0, PRIMARY KEY (hashList))");
                $DB->query("INSERT INTO tmphlcracks (hashList) SELECT id FROM hashlists WHERE id IN ($hlisty)");

                $crack_cas = time();
                foreach ($data as $dato) {
                    // for non empty lines update solved hashes
                    if ($dato == "") {
                        continue;
                    }
                    $elementy = explode($separator, $dato);
                    $podminka = "";
                    $plain = "";
                    switch ($format) {
                        case 0:
                            // save regular password
                            $hash = substr($DB->quote($elementy[0]), 1, -1);
                            switch (count($elementy)) {
                                case 2:
                                    // unsalted hashes
                                    $salt = "";
                                    $plain = substr($DB->quote($elementy[1]), 1, -1);
                                    break;
                                case 3:
                                    // salted hashes
                                    $salt = substr($DB->quote($elementy[1]), 1, -1);
                                    $plain = substr($DB->quote($elementy[2]), 1, -1);
                                    file_put_contents("salt_log.txt", "$dato\n$hash###$salt###$plain\n", FILE_APPEND);
                                    break;
                            }
                            $podminka = "$tbl.hash='$hash' AND $tbl.salt='$salt'";
                            break;
                        case 1:
                            // save cracked wpa password
                            $network = substr($DB->quote($elementy[0]), 1, -1);
                            $plain = substr($DB->quote($elementy[1]), 1, -1);
                            // QUICK-FIX WPA/WPA2 strip mac address
                            if (preg_match("/.+:[0-9a-f]{12}:[0-9a-f]{12}$/", $network) === 1) {
                                // TODO: extend DB model by MACs and implement detection
                                $network = substr($network, 0, strlen($network) - 26);
                            }
                            $podminka = "$tbl.essid='$network'";
                            break;
                        case 2:
                            // save binary password
                            $plain = substr($DB->quote($elementy[1]), 1, -1);
                            break;
                    }

                    // make the query
                    $qu = "UPDATE $tbl JOIN tmphlcracks ON tmphlcracks.hashList=$tbl.hashList SET $tbl.plaintext='$plain',$tbl.time=$crack_cas,$tbl.chunk=$cid,tmphlcracks.cracked=tmphlcracks.cracked+1 WHERE $tbl.hashList IN ($hlisty) AND $tbl.plaintext IS NULL" . ($podminka != "" ? " AND " . $podminka : "");
                    $res = $DB->query($qu);

                    // check if the update went right
                    if ($res) {
                        $affec = $res->rowCount();
                        if ($affec > 0) {
                            $cracked++;
                        } else {
                            $skipped++;
                        }
                    } else {
                        $errors++;
                    }

                    // everytime we pass statustimer
                    if (time() >= $crack_cas + $task->getStatusTimer()) {
                        // update the cache
                        Util::writecache();
                    }
                }
                Util::writecache();
                // drop the temporary cache
                $DB->query("DROP TABLE tmphlcracks");
            }
        }

        /**
         * A new section begins.
         */
        if ($errors != 0) {
            API::sendErrorResponse($action, $errors . " occured when updating hashes.");
        }
        if ($chunk->getState() == 10) {       //TODO Don't compare with 10
            // the chunk was manually interrupted
            $chunk->setState(6); //TODO Don't use 6
            $FACTORIES::getChunkFactory()->update($chunk);
            API::sendErrorResponse($action, "Chunk was manually interrupted.");
        }
        // just inform the agent about the results
        echo "solve_ok" . $separator . $cracked . $separator . $skipped;      //TODO does the api have a 'success' command?

        /** Check if the task is done */
        $taskdone = false;
        if ($normalizedProgress == $normalizedTotal && $task->getProgress() == $task->getKeyspace()) {
            // chunk is done and the task has been fully dispatched
            $incompleteFilter = new QueryFilter("rprogress", 10000, "<");    //TODO Is there a way to get name of rows?
            $taskFilter = new QueryFilter("taskId",$taskID, "=");
            $count = $FACTORIES::getChunkFactory()->countFilter(array("filter" => array($incompleteFilter, $taskFilter)));
            if ($count== 0) {
                // this was the last incomplete chunk!
                $taskdone = true;
            }
        }

        if ($taskdone) {
            // task is fully dispatched and this last chunk is done, deprioritize it
            $task->setPriority(0);
            $FACTORIES::getTaskFactory()->update($task);

            // email task done
            if ($CONFIG->getVal("emailtaskdone") == "1") {
                @mail($CONFIG->getVal("emailaddr"), "Hashtopus: task finished", "Your task ID $taskID was finished by agent $agentID.");
            }
        }

        //TODO Don't compare with ints
        switch ($state) {
            case 4:
                // the chunk has finished (exhausted)
                if ($length == $assignment->getBenchmark() && $assignment->getAutoAdjust() == 1 && $taskdone == false) {
                    // the chunk was originaly meant for this agent, the autoadjust is on, the agent is still at this task and the task is not done
                    $delka = time() - $chunk->getDispatchTime();
                    $newbench = ($assignment->getBenchmark() / $delka) * $chunk->getTime();
                    // update the benchmark
                    $assignment->setSpeed(0);
                    $assignment->setBenchmark($newbench);
                    $FACTORIES::getAssignmentFactory()->update($assignment); //TODO Does this check for both fk?
                }
                break;
            case 5:
                // the chunk has finished (cracked whole hashList)
                // deprioritize all tasks and unassign all agents
                if ($superhash && $hlistyzap == $hlisty) {
                    if ($hlistyzap != "") {
                        $hlistyzap .= ",";
                    }
                    $hlistyzap .= $hashListID;
                }
                //TODO is there an IN_Filter?
                $DB->query("UPDATE tasks SET priority=0 WHERE hashList IN ($hlistyzap)");

                // email hashList done
                if ($CONFIG->getVal("emailhldone") == "1") {
                    @mail($CONFIG->getVal("emailaddr"), "Hashtopus: hashList cracked", "Your hashlists ID $hlistyzap were cracked by agent $agentID.");
                }
                break;
            case 6:
                // the chunk was aborted
                $assignment->setSpeed(0);
                $FACTORIES::getAssignmentFactory()->update($assignment);
                break;
            default:
                // the chunk isn't finished yet, we will send zaps
                //TODO FIXME with exists() or count-query
                $res = $DB->query("SELECT 1 FROM hashlists WHERE id IN ($hlistyzap) AND cracked<hashcount");
                echo $separator;
                if ($res->rowCount() > 0) {
                    // there are some hashes left uncracked in this (super)hashList
                    if (false) {
                        // TODO FIXME if the agent is still assigned, update its speed
                        $assignment->setSpeed($speed);
                        $FACTORIES::getAssignmentFactory()->update($assignment);
                    }
                    $DB->query("START TRANSACTION");
                    switch ($format) {
                        case 0:     //TODO Don't switch with 0
                            // return text zaps
                                    //TODO new Zapqueue
                            $res = $DB->query("SELECT hashes.hash, hashes.salt FROM hashes JOIN zapqueue ON hashes.hashList=zapqueue.hashList AND zapqueue.agent=$agentID AND hashes.time=zapqueue.time AND hashes.chunk=zapqueue.chunk WHERE hashes.hashList IN ($hlistyzap)");
                            $pocet = $res->rowCount();
                            break;
                        case 1:
                            // return hccap zaps (essids)
                            //TODO new Zapqueue
                            $res = $DB->query("SELECT hashes_binary.essid AS hash, '' AS salt FROM hashes_binary JOIN zapqueue ON hashes_binary.hashList=zapqueue.hashList AND zapqueue.agent=$agentID AND hashes_binary.time=zapqueue.time AND hashes_binary.chunk=zapqueue.chunk WHERE hashes_binary.hashList IN ($hlistyzap)");
                            $pocet = $res->rowCount();
                            break;
                        case 2:
                            // binary hashes don't need zaps, there is just one hash
                            $pocet = 0;

                        //TODO Default case
                    }

                    if ($pocet > 0) {
                        echo "zap_ok" . $separator . $pocet . $newline;
                        // list the zapped hashes
                        while ($line = $res->fetch()) {
                            echo $line["hash"];
                            if ($line["salt"] != "") {
                                echo $separator . $line["salt"];
                            }
                            echo $newline;
                        }
                    } else {
                        echo "zap_no" . $separator . "0" . $newline;
                    }
                    // update hashList age for agent to this task
                    // TODO Zapqueue
                    $DB->query("DELETE FROM zapqueue WHERE hashList IN ($hlistyzap) AND agent=$agentID");
                    $DB->query("COMMIT");
                } else {
                    // kill the cracking agent, the (super)hashList was done
                    echo "stop";
                }
                break;
        }
    }
}