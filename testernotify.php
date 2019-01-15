<?php
/**
 * Created by PhpStorm.
 * User: fatih
 * Date: 22.11.18
 * Time: 08:38
 */
require_once './vendor/autoload.php';
require 'helper_functions.php';

// load environment variables.
$dotEnv = Dotenv\Dotenv::create(__DIR__);
$dotEnv->load();

$options = getopt('v::n::');

$debug = array_key_exists('v', $options);

$noSave = array_key_exists('n', $options);

$state = getState();
if (array_key_exists('lookup', $state) && count($state['lookup']) > 0) {
    $lookup = $state['lookup'];
} else {
    $lookup = buildUserLookupTable();
}

// get all tickets that are "ready to test" in the Entwicklung project
// that have changed since the last run.
if ($debug) {
    echo "Processing tickets for slack..." . PHP_EOL;
}
$work = getTicketsToProcess(intval($state['lastRun']), $lookup);

if ($debug && count($work) > 0) {
    echo "Sending slack messages" . PHP_EOL;
} elseif ($debug) {
    echo "No tickets selected." . PHP_EOL;
}
sendSlackMessages($work);

// update the lastRun for the slack messages
$state['lastRun'] = time();

// if this is the first run of the day, do it again, but for emails.
if (date('d') !== date('d', $state['lastMail'])) {

    if ($debug) {
        echo "Processing tickets for postmark..." . PHP_EOL;
    }
    $fullDay = getTicketsToProcess(intval($state['lastMail']), $lookup);

    if ($debug) {
        echo "Sending Email messages" . PHP_EOL;
    }
    sendEmailMessages($fullDay);

    // update the state since we sent the mail
    $state['lastMail'] = time();

    // regen the user lookup table once a day
    if ($debug) {
        echo "Regenerate the lookup table." . PHP_EOL;
    }
    $state['lookup'] = buildUserLookupTable();
}

if (!$noSave) {
    if ($debug) {
        echo "Saving state... " . PHP_EOL;
    }
    file_put_contents(getenv('RUNFILE'), json_encode($state));
}
