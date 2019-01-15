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
$dotenv = Dotenv\Dotenv::create(__DIR__);
$dotenv->load();

$options = getopt('v::');

$debug = array_key_exists('v', $options);

$testLimit = [
    6061,
    6062,
    6149,
];

if ($debug && count($testLimit) > 0) {
    echo "Testing limited to ticket #: ";
    foreach ($testLimit as $ticket) {
        echo $ticket . " ";
    }
    echo PHP_EOL;
}

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

if ($debug) {
    echo "Sending slack messages" . PHP_EOL;
}
sendSlackMessages($work);
$state['lastRun'] = date('U');

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

    $state['lastMail'] = date('U');
    // regen the user lookup table
    if ($debug) {
        echo "Regenerate the lookup table.";
    }
    $state['lookup'] = buildUserLookupTable();

}

if ($debug) {
    echo "Saving state.." . PHP_EOL;
}
file_put_contents(getenv('RUNFILE'), json_encode($state));
