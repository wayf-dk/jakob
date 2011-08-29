<?php
/**
 * JAKOB
 *
 * @category   WAYF
 * @package    JAKOB
 * @subpackage Connector
 * @author     Jacob Christiansen <jach@wayf.dk>
 * @copyright  Copyright (c) 2011 Jacob Christiansen, WAYF (http://www.wayf.dk)
 * @license    http://www.opensource.org/licenses/mit-license.php MIT License
 * @version    $Id$
 * @link       $URL$
 */

/**
 * @namespace
 */
namespace WAYF\Connector\Worker;

/**
 * @uses
 */
use WAYF\Connector\Worker;

/**
 * Worker class based on Gearman
 */
class JakobWorker implements Worker
{
    /**
     * Gearman worker
     * @var \GearmanWorker
     */
    private $_gworker = null;

    /**
     * constructor
     */
    public function __construct()
    {
        $this->_gworker = new \GearmanWorker();
        $this->_gworker->addServer();
    }

    /**
     * Register work for the worker
     *
     * @param string The name of the work
     * @param \WAYF\Connector\Job Job object
     */
    public function addWork($name, \WAYF\Connector\Job $obj)
    {
        $this->_gworker->addFunction($name, array($obj, 'execute'));
    }

    /**
     * Perform work
     */
    public function work()
    {
        while ($this->_gworker->work()) {
            if (GEARMAN_SUCCESS != $this->_gworker->returnCode()) {
                echo "Worker failed: " . $this->_gworker->error() . "\n";
            }
        }
    }
}