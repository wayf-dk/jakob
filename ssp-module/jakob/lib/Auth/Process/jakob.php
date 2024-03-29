<?php
class sspmod_jakob_Auth_Process_jakob extends SimpleSAML_Auth_ProcessingFilter
{
    private $_jConfig;

    public function __construct($config, $reserved)
    {
        $this->_jConfig = SimpleSAML_Configuration::getConfig('module_jakob.php');
    }

    public function process(&$state)
    {
        assert('is_array($state)');
        assert('array_key_exists("Destination", $state)');
        assert('array_key_exists("entityid", $state["Destination"])');

        if (isset($state['saml:sp:IdP'])) {
            $metadata        = SimpleSAML_Metadata_MetaDataStorageHandler::getMetadataHandler();
            $idpmeta         = $metadata->getMetaData($state['saml:sp:IdP'], 'saml20-idp-remote');
            $state['Source'] = $idpmeta;
        }

        assert('array_key_exists("Source", $state)');
        assert('array_key_exists("entityid", $state["Source"])');

        // Init DB
        $dsn      = $this->_jConfig->getString('dsn');
        $username = $this->_jConfig->getString('username');
        $password = $this->_jConfig->getString('password');
        $table    = $this->_jConfig->getString('table');
        $timeout  = $this->_jConfig->getInteger('timeout');

        try {
            $db = @new sspmod_jakob_DB($dsn, $username, $password, array(
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => $timeout,
                PDO::ATTR_PERSISTENT   
            ));
        } catch (PDOException $e) {
            SimpleSAML_Logger::error(
                'JAKOB: Connection to JAKOB database failed: ' .
                $e->getMessage()
            );
            return;
        }

        // Get jaobhash value
        $source      = $state['Source']['entityid'];
        $destination = $state['Destination']['entityid'];
        $jobid     = $this->getJobHash($source, $destination);

        // Grab job configuration
        $query = "SELECT * FROM `" . $table . "` WHERE `jobid` = :jobid;";

        try{
            $res = $db->fetch_one($query, array('jobid' => $jobid));
        } catch (PDOException $e) {
            SimpleSAML_Logger::error(
                'JAKOB: Running query on JAKOB database failed: ' .
                $e->getMessage()
            );
            return;
        }

        // redirect if job exists
        if ($res) {
            $params = array();
            // User interaction nessesary. Silence the request to JAKOB    
            if (isset($state['isPassive']) && $state['isPassive'] == true) {
                $params['silence'] = 'on';
            }

            $params['attributes']   = json_encode($state['Attributes']);
            $params['returnURL']    = SimpleSAML_Module::getModuleURL('jakob/jakob.php');
            $params['returnMethod'] = 'post';
            $params['returnParams'] = json_encode(array('StateId' => SimpleSAML_Auth_State::saveState($state, 'jakob:request')));
            $joburl                 = $this->_jConfig->getString('joburl');
            $jakoburl               = $joburl . $jobid;

            // Generate signature on request
            $signer = new sspmod_jakob_Signer();
            $signer->setUp($this->_jConfig->getString('consumersecret'), $params);
            $params['consumerkey'] = $this->_jConfig->getString('consumerkey');
            $params['signature'] = $signer->sign();
            
            SimpleSAML_Logger::info('Calling JAKOB with jobID: ' . $jobid);

            // Redirect to JAKOB
            SimpleSAML_Utilities::postRedirect($jakoburl, $params);
        } else {
            SimpleSAML_Logger::info('JAKOB jobID not found: ' . $jobid);
        }
    }

    /**
     * Calculate the jobhash value
     */
    public function getJobHash($source, $destination)
    {
        return hash('sha1', $source . '|' . $this->_jConfig->getString('salt') . '|' . $destination);
    }
}
