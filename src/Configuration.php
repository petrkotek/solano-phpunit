<?php
/*
 * This file is part of Solano-PHPUnit.
 *
 * (c) Solano Labs https://www.solanolabs.com/
 *
 */

/**
 * Configuration for Solano-PHPUnit
 *
 * @package    Solano-PHPUnit
 * @author     Isaac Chapman <isaac@solanolabs.com>
 * @copyright  Solano Labs https://www.solanolabs.com/
 * @link       https://www.solanolabs.com/
 */
class SolanoLabs_PHPUnit_Configuration
{
    /**
     * @var string
     */
    private $configFile = '';
    
    /**
     * @var string
     */
    public $workingDir = '';
    
    /**
     * @var string
     */
    public $tempDir = '';
    
    /**
     * @var DOMDocument
     */
    public $domDoc;
    
    /**
     * @var array
     */
    public $args = array();
    
    /**
     * @var array
     */
    public $testFiles = array();

    /**
     * @var array
     */
    public $cliTestFiles = array();

    /**
     * @var array
     */
    public $excludeFiles = array();

    /**
     * @var array
     */
    public $parseErrors = array();
    
    /**
     * @var string
     */
    public $outputFile = '';
    
    /**
     * @var string
     */
    public $bootstrap = '';
    
    /**
     * @var array
     */
    public $includePaths = array();
    
    /**
     * @var array
     */
    public $logTargets = array();

    /**
     * @var boolean
     */
    public $splitTests = false;

    /**
     * @var boolean
     */
    public $ignoreExclude = false;

    /**
     * @var boolean
     */
    public $configDebug = false;

    /**
     * @var boolean
     */
    public $minXmlFile = false;

    /**
     * @var sting
     */
    public $testsuiteFilter = '';

    /**
     * Returns configuration 
     *
     * @param  array                     $args
     * @return SolanoLabs_PHPUnit_Configuration
     */
    public static function parseArgs($args)
    {
        $config = new static;
        
        $config->args = $args;

        $config->loadConfigFile();
        if (count($config->parseErrors)) { return $config; }

        // Independent tasks
        $config->checkTempDir();
        $config->findCliTestFiles();
        $config->findLogTargets();
        $config->setOutputFile();
        $config->checkSplit();
        $config->checkIgnoreExclude();
        $config->checkConfigDebug();
        $config->checkTestsuiteOption();

        if (count($config->parseErrors)) { return $config; }

        $config->handleIncludePaths();
        $config->handleBootstrap();

        if (count($config->parseErrors)) { return $config; }

        // rekey args
        $trimmedArgs = array();
        foreach($config->args as $arg) {
            $trimmedArgs[] = $arg;
        }
        $config->args = $trimmedArgs;

        // Add --debug automatically for tddium|solano
        if (getenv('TDDIUM') && !in_array('--debug', $config->args)) {
            $config->args[] = '--debug';
        }

        return $config;
    }

    /**
     * Check if --split was supplied
     */
    private function checkSplit()
    {
        if ($key = array_search('--split', $this->args)) {
            $this->splitTests = true;
            unset($this->args[$key]);
        }
    }

    /**
     * Check if --ignore-exclude was supplied
     */
    private function checkIgnoreExclude()
    {
        if ($key = array_search('--ignore-exclude', $this->args)) {
            $this->ignoreExclude = true;
            unset($this->args[$key]);
        }
    }

    /**
     * Check if --config-debug was supplied
     */
    private function checkConfigDebug()
    {
        if ($key = array_search('--config-debug', $this->args)) {
            $this->configDebug = true;
            unset($this->args[$key]);
        }
    }

    /**
     * Adds cli specified files to the configuration
     */
    private function findCliTestFiles()
    {
        if ($key = array_search('--files', $this->args)) {
            if (!isset($this->args[1 + $key])) {
                $this->parseErrors[] = "### Error: No files specified";
            } else {
                $files = explode(',', $this->args[1 + $key]);
                foreach ($files as $file) {
                    if (!SolanoLabs_PHPUnit_Util::isRootRelativePath($file)) {
                        $file = SolanoLabs_PHPUnit_Util::truepath($file, getcwd());
                    }
                    if (!file_exists($file)) {
                        $this->parseErrors[] = "### Error: File does not exist: " . $file;
                    } else {
                        $this->cliTestFiles[] = $file;
                    }
                }
                unset($this->args[1 + $key]);
                unset($this->args[$key]);
            }
        }
        $this->cliTestFiles = array_unique($this->cliTestFiles);
        sort($this->cliTestFiles);
        $this->testFiles = $this->cliTestFiles;
    }

    /**
     * Specifies which temp directory to use
     */
    private function checkTempDir()
    {
        if ($key = array_search('--temp-dir', $this->args)) {
            if (!isset($this->args[1 + $key])) {
                $this->parseErrors[] = "### Error: No temp directory specified";
            }
            if (!file_exists($this->args[1 + $key])) {
                $this->parseErrors[] = "### Error: Specified temp directory does not exist";
            } elseif (is_file($this->args[1 + $key])) {
                $this->parseErrors[] = "### Error: Specified temp directory is a file";
            } else {
                $this->tempDir = SolanoLabs_PHPUnit_Util::truepath($this->args[1 + $key]);
                unset($this->args[1 + $key]);
                unset($this->args[$key]);
            }
        } else {
            // Use ENV if they are set
            foreach (array('TDDIUM_TMPDIR', 'TMPDIR', 'TMP_DIR') as $env) {
                if (isset($_ENV[$env]) && is_dir($_ENV[$env])) {
                    $this->tempDir = SolanoLabs_PHPUnit_Util::truepath($_ENV[$env]);
                    break;
                }
            }
        }
        if (!$this->tempDir) {
            $this->tempDir = sys_get_temp_dir();
        }
    }

    /**
     * Determines which phpunit configuration file to use and loads it
     */
    private function loadConfigFile()
    {
        // PHPUnit configuration file
        if ($key = array_search('--configuration', $this->args)) {
            if (!isset($this->args[1 + $key])) {
                $this->parseErrors[] = "### Error: No configuration file specified";
            }
            $file = $this->args[1 + $key];
            if (!SolanoLabs_PHPUnit_Util::isRootRelativePath($file)) {
                $file = SolanoLabs_PHPUnit_Util::truepath($file);
            }
            if (!file_exists($file)) {
                $this->parseErrors[] = "### Error: Specified configuration file does not exist";
            } elseif (is_dir($file)) {
                $this->parseErrors[] = "### Error: Specified configuration file is a directory";
            } else {
                $this->configFile = $file;
                unset($this->args[1 + $key]);
                unset($this->args[$key]);
            }
        } elseif (file_exists('phpunit.xml')) {
            $this->configFile = SolanoLabs_PHPUnit_Util::truepath('phpunit.xml');
        } elseif (file_exists('phpunit.xml.dist')) {
            $this->configFile = SolanoLabs_PHPUnit_Util::truepath('phpunit.xml.dist');
        } else {
            # No configuration file specified and defaults do not exist, use miminal version as base
            $this->configFile = SolanoLabs_PHPUnit_Util::truepath(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'phpunit.xml.minimal');
            $this->workingDir = getcwd();
            $this->minXmlFile = true;
        }
        // Ensure the configuration file is valid XML
        if (!count($this->parseErrors)) {
            $this->domDoc = new DomDocument();
            if (!$this->domDoc->load($this->configFile)) {
                $this->parseErrors[] = "### Error: " . $this->configFile . " could not be read";
            } elseif (!$this->minXmlFile) {
                $this->workingDir = dirname($this->configFile);
            }
        }
    }

    /**
     * Determines include_path from cli and/or phpunit config file
     */
    private function handleIncludePaths() {
        // Was an --include-path provided at the command line?
        if ($key = array_search('--include-path', $this->args)) {
            if (!isset($this->args[1 + $key])) {
                $this->parseErrors[] = "### Error: No include path specified";
            } else {
                $path = SolanoLabs_PHPUnit_Util::truepath($this->args[1 + $key]);
                $this->includePaths[] = $path;
                ini_set('include_path', $path . PATH_SEPARATOR . ini_get('include_path'));
                unset($this->args[1 + $key]);
                unset($this->args[$key]);
            }
        }
        // Was an incudePath provided in the config Xml file?
        $xpath = new DOMXPath($this->domDoc);
        foreach ($xpath->query('php/includePath') as $node) {
            $path = (string) $node->nodeValue;
            if ($path) {
                if (!SolanoLabs_PHPUnit_Util::isRootRelativePath($path)) {
                    $path = SolanoLabs_PHPUnit_Util::truepath($path, $this->workingDir);
                }
                $this->includePaths[] = $path;
                ini_set('include_path', $path . PATH_SEPARATOR . ini_get('include_path'));
            }
        }
    }

    /**
     * Determines if a bootstrap file is set by either cli or phphunit config file
     */
    private function handleBootstrap()
    {
        // Was an --bootstrap provided at the command line (takes precedence over config file setting?
        if ($key = array_search('--bootstrap', $this->args)) {
            if (!isset($this->args[1 + $key])) {
                $this->parseErrors[] = "### Error: No bootstrap file specified";
            } else {
                $file = SolanoLabs_PHPUnit_Util::truepath($this->args[1 + $key]);
                if (!file_exists($file)) {
                    $this->parseErrors[] = "### Error: Nonexistent bootstrap file: " . $file;
                    return;
                }

                $this->bootstrap = $file;
                unset($this->args[1 + $key]);
                unset($this->args[$key]);
            }
        } else {
            // Is a bootstrap specified in the XML?
            $root = $this->domDoc->documentElement;
            if ($root->hasAttribute('bootstrap')) {
                $file = SolanoLabs_PHPUnit_Util::truepath($root->getAttribute('bootstrap'), $this->workingDir);
                if (!file_exists($file)) {
                    $this->parseErrors[] = "### Error: XML config file: $this->configFile\n### contains invalid bootstrap attribute: $file";
                    return;
                } else {
                    $this->bootstrap = $file;
                }
            }
        }
    }

    /**
     * Was a --testsuite supplied?
     */
    private function checkTestsuiteOption()
    {
        if ($key = array_search('--testsuite', $this->args)) {
            if (!isset($this->args[1 + $key])) {
                $this->parseErrors[] = "### Error: No --testsuite pattern specified";
            } else {
                $this->testsuiteFilter = $this->args[1 + $key];
                unset($this->args[1 + $key]);
                unset($this->args[$key]);
            }
        }
    }

    /**
     * Extracts type and target from <log type="" target="" /> nodes in the phpunit config file
     */
    private function findLogTargets()
    {
        $logNodes = $this->domDoc->getElementsByTagName('log');
        if (!$logNodes->length) { return; }
        foreach($logNodes as $logNode) {
            if ($logNode->hasAttribute('type') && $logNode->hasAttribute('target') && false === strpos($logNode->getAttribute('target'), '://')) { // Do not track stream targets
                $this->logTargets[$logNode->getAttribute('type')] = SolanoLabs_PHPUnit_Util::truepath($this->workingDir . DIRECTORY_SEPARATOR . $logNode->getAttribute('target'));
            }
        }
    }

    /**
     * Determines what the output file
     */
    private function setOutputFile()
    {
        $file = SolanoLabs_PHPUnit_Util::truepath('tddium_output.json');
        if ($env_file = getenv('TDDIUM_OUTPUT_FILE')) {
            $file = SolanoLabs_PHPUnit_Util::truepath($env_file);
        } elseif (getenv('TDDIUM')) {
            $file = SolanoLabs_PHPUnit_Util::truepath(getenv('HOME') . DIRECTORY_SEPARATOR . 'phpunit.json');
        }

        if ($key = array_search('--tddium-output-file', $this->args)) {
            if (!isset($this->args[1 + $key])) {
                $this->parseErrors[] = "### Error: No tddium output file specified";
                return;
            } else {
                $file = SolanoLabs_PHPUnit_Util::truepath($this->args[1 + $key]);
                unset($this->args[1 + $key]);
                unset($this->args[$key]);
            }
        }

        if (file_exists($file)) {
            if (!is_writable($file)) {
                $this->parseErrors[] = "### Error: $file is not writable";
                return;
            }
        } elseif (!is_dir(dirname($file))) {
            $this->parseErrors[] = "### Error: Directory does not exist: " . dirname($file);
            return;
        } else{
            try {
                $fp = fopen($file, 'w');
                fwrite($fp, '{"byfile":{}}'); // Write empty entry for minimal race condition possibility
                fclose($fp);
                unlink($file);
            } catch (Exception $e) {
                $this->parseErrors[] = "### Error: $file is not writable: " . $e->getMessage();
            }
        }

        $this->outputFile = $file;
        // Set output file env variable in case of fatal error
        putenv('SOLANO_PHPUNIT_OUTPUT_FILE=' . $file);
    }
}
