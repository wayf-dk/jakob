<?php
include '_init.php';

// Protection against session fixation attacks
session_regenerate_id(true);

/**
 * If JAKOB.id is set and $_POST['token'] is equale, then we are resuming 
 * execution
 */
if ((isset($_SESSION['JAKOB.id']) && isset($_POST['token'])) && $_SESSION['JAKOB.id'] == $_POST['token']) {
    unset($_SESSION['JAKOB.id']);
    $session = unserialize($_SESSION['JAKOB_Session']);
    $tasks = $session['tasks'];
    $attributes = $session['attributes'];
    $returnurl = $session['returnURL'];
    $returnmethod = $session['returnMethod'];
    $pendingjobs = $session['pendingjobs'];
    $returnparams = $session['returnParams'];
    $silence = $session['silence'];
    $consumerkey = $session['consumerkey'];
} else {
    // Process the request
    try {
        $request = new \WAYF\Request($jakob_config['database']);
        $request->handleRequest();
        // Get job configuration
        $jc = new \WAYF\JobConfigurationHelper($jakob_config['database']);
        $tasks = $jc->load($request->getJobid());
        $attributes = $request->getAttributes();
        $returnurl = $request->getReturnURL();
        $returnmethod = $request->getReturnMethod();
        $returnparams = $request->getReturnParams();
        $silence = $request->getSilence();
        $consumerkey = $request->getConsumer();
    } catch(\WAYF\RequestException $re) {
        $data = array(
            'errortitle' => 'Request error',
            'errormsg' => $re->getMessage(),
            't' => $t,
            'lang' => $_SESSION['lang']
        );
        $template->setTemplate('error')->setData($data)->render();
    }
}

// Setup the attribute collector
$attr_col = new \WAYF\AttributeCollector();
$jakob_config['silence'] = $silence;
$attr_col->setConfig($jakob_config);
$attr_col->setLogger($logger);
$storage = \WAYF\StoreFactory::createInstance($jakob_config['connector.storage']);
$storage->initialize();
$client = new \WAYF\Client\JakobClient($jakob_config['gearman.jobservers']);
$client->setStorage($storage);
$attr_col->setClient($client);

try {
    $attr_col->setAttributes($attributes);
    $attr_col->setTasks($tasks);
    if (isset($pendingjobs)) {
        $attr_col->setPendingJobs($pendingjobs);
    }
    $attributes = $attr_col->processTasks();
} catch(WAYF\Exceptions\TimeoutException $e) {
    // Regular timeout, show feedback to user
    $session = array(
        'attributes' => $attr_col->getAttributes(),
        'tasks' => $attr_col->getTasks(),
        'pendingjobs' => $attr_col->getPendingJobs(),
        'returnURL' => $returnurl,
        'returnMethod' => $returnmethod,
        'returnParams' => $returnparams,
        'silence' => $silence,
        'consumerkey' => $consumerkey,
    );
    $_SESSION['JAKOB_Session'] = serialize($session);
    $_SESSION['JAKOB.id'] = \WAYF\Utilities::generateID();
    $template->setTemplate('timeout')->setData(array('token' => $_SESSION['JAKOB.id'], 't' => $t, 'lang' => $_SESSION['lang']))->render();
} catch(WAYF\Exceptions\FatalTimeoutException $e) {
    // fatal timeout, return what we have
    try {
        $attr_col->emptyResultsQueue();
    } catch (\Exception $e) {
        $logger->log(JAKOB_ERROR, 'Something went wrong when collecting the last attributes. ' . var_export($e, true));
    }
    $attributes = $attr_col->getAttributes();
} catch(\Exception $e) {
    // Some unknown errro have happend
    $data = array(
        'errortitle' => 'An error has occured',
        'errormsg' => $e->getMessage(),
        't' => $t,
        'lang' => $_SESSION['lang']
    );
    $logger->log(JAKOB_ERROR, 'An error has occured' . var_export($e, true));
    $template->setTemplate('error')->setData($data)->render();
}   

// Destroy session
$_SESSION = array();
// Set-Cookie to invalidate the session cookie
if (isset($_COOKIES[session_name()])) { 
    $params = session_get_cookie_params();
    setcookie(session_name(), '', 1, $params['path'], $params['domain'], $params['secure'], isset($params['httponly']));
}
session_destroy();

$data = array();

foreach($returnparams AS $k => $v) {
    $data[$k] = $v;
}

$data['attributes'] = json_encode($attributes);

/**
 * Sign the response with the same shared secret used by the consumer
 */
try {
    $consumer = new \WAYF\Consumer($jakob_config['database']);
    $consumer->consumerkey = $consumerkey;
    $consumer->load();
} catch(\WAYF\ConsumerException $e) {
    // Consumer could not be found. Most likely a DB error
    $data = array(
        'errortitle' => 'Consumer could not be found', 
        'errormsg' => $e->getMessage(),
        't' => $t,
        'lang' => $_SESSION['lang']
    );
    $logger->log(JAKOB_ERROR, 'Consumer could not be found' . var_export($e, true));
    $template->setTemplate('error')->setData($data)->render();
}

$signparams = $data;

$signer = new \WAYF\Security\Signer\GetRequestSigner();
$signer->setUp($consumer->consumersecret, $signparams);

$data['signature'] = $signer->sign($logger);

// Return the result
if ($returnmethod == 'post') {
    $data = array('post' => $data, 'destination' => $returnurl);
    $template->setTemplate('post', false)->setData($data)->render();
} else if ($returnmethod == 'get') {
    $data = array('url' => $returnurl . '?' . http_build_query($data));
    $template->setTemplate('get')->setData($data)->render();
} else if ($returnmethod == 'raw') { 
    $template->setTemplate('raw', FALSE)->setData($data)->render();
}
