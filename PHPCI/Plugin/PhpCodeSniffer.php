<?php
/**
* PHPCI - Continuous Integration for PHP
*
* @copyright    Copyright 2013, Block 8 Limited.
* @license      https://github.com/Block8/PHPCI/blob/master/LICENSE.md
* @link         http://www.phptesting.org/
*/

namespace PHPCI\Plugin;

use PHPCI;
use PHPCI\Builder;
use PHPCI\Model\Build;

/**
* PHP Code Sniffer Plugin - Allows PHP Code Sniffer testing.
* @author       Dan Cryer <dan@block8.co.uk>
* @package      PHPCI
* @subpackage   Plugins
*/
class PhpCodeSniffer implements PHPCI\Plugin, PHPCI\ZeroConfigPlugin
{
    /**
     * @var \PHPCI\Builder
     */
    protected $phpci;

    /**
     * @var array
     */
    protected $suffixes;

    /**
     * @var string
     */
    protected $directory;

    /**
     * @var string
     */
    protected $standard;

    /**
     * @var string
     */
    protected $tab_width;

    /**
     * @var string
     */
    protected $encoding;

    /**
     * @var int
     */
    protected $allowed_errors;

    /**
     * @var int
     */
    protected $allowed_warnings;

    /**
     * @var string, based on the assumption the root may not hold the code to be
     * tested, exteds the base path
     */
    protected $path;

    /**
     * @var array - paths to ignore
     */
    protected $ignore;

    public static function canExecute($stage, Builder $builder, Build $build)
    {
        if ($stage == 'test') {
            return true;
        }

        return false;
    }

    /**
     * @param \PHPCI\Builder $phpci
     * @param \PHPCI\Model\Build $build
     * @param array $options
     */
    public function __construct(Builder $phpci, Build $build, array $options = array())
    {
        $this->phpci = $phpci;
        $this->build = $build;
        $this->suffixes = array('php');
        $this->directory = $phpci->buildPath;
        $this->standard = 'PSR2';
        $this->tab_width = '';
        $this->encoding = '';
        $this->path = '';
        $this->ignore = $this->phpci->ignore;
        $this->allowed_warnings = 0;
        $this->allowed_errors = 0;

        if (isset($options['zero_config']) && $options['zero_config']) {
            $this->allowed_warnings = -1;
            $this->allowed_errors = -1;
        }

        if (isset($options['suffixes'])) {
            $this->suffixes = (array)$options['suffixes'];
        }

        if (isset($options['directory'])) {
            $this->directory = $options['directory'];
        }

        if (isset($options['standard'])) {
            $this->standard = $options['standard'];
        }

        if (!empty($options['tab_width'])) {
            $this->tab_width = ' --tab-width='.$options['tab_width'];
        }

        if (!empty($options['encoding'])) {
            $this->encoding = ' --encoding=' . $options['encoding'];
        }

        if (isset($options['path'])) {
            $this->path = $options['path'];
        }

        if (isset($options['ignore'])) {
            $this->ignore = $options['ignore'];
        }

        if (isset($options['allowed_warnings'])) {
            $this->allowed_warnings = (int)$options['allowed_warnings'];
        }

        if (isset($options['allowed_errors'])) {
            $this->allowed_errors = (int)$options['allowed_errors'];
        }
    }

    /**
    * Runs PHP Code Sniffer in a specified directory, to a specified standard.
    */
    public function execute()
    {
        list($ignore, $standard, $suffixes) = $this->getFlags();

        $phpcs = $this->phpci->findBinary('phpcs');

        if (!$phpcs) {
            $this->phpci->logFailure('Could not find phpcs.');
            return false;
        }

        $this->phpci->logExecOutput(false);

        $cmd = $phpcs . ' --report=json %s %s %s %s %s "%s"';
        $this->phpci->executeCommand(
            $cmd,
            $standard,
            $suffixes,
            $ignore,
            $this->tab_width,
            $this->encoding,
            $this->phpci->buildPath . $this->path
        );

        $output = $this->phpci->getLastOutput();
        list($errors, $warnings, $data) = $this->processReport(json_decode(trim($output), true));

        $this->phpci->logExecOutput(true);

        $success = true;
        $this->build->storeMeta('phpcs-warnings', $warnings);
        $this->build->storeMeta('phpcs-errors', $errors);
        $this->build->storeMeta('phpcs-data', $data);

        if ($this->allowed_warnings != -1 && $warnings > $this->allowed_warnings) {
            $success = false;
        }

        if ($this->allowed_errors != -1 && $errors > $this->allowed_errors) {
            $success = false;
        }

        return $success;
    }

    protected function getFlags()
    {
        $ignore = '';
        if (count($this->ignore)) {
            $ignore = ' --ignore=' . implode(',', $this->ignore);
        }

        if (strpos($this->standard, '/') !== false) {
            $standard = ' --standard='.$this->directory.$this->standard;
        } else {
            $standard = ' --standard='.$this->standard;
        }

        $suffixes = '';
        if (count($this->suffixes)) {
            $suffixes = ' --extensions=' . implode(',', $this->suffixes);
        }

        return array($ignore, $standard, $suffixes);
    }

    protected function processReport($data)
    {
        if (!is_array($data)) {
            throw new \Exception('Could not process PHPCS report JSON.');
        }

        $errors = $data['totals']['errors'];
        $warnings = $data['totals']['warnings'];

        $rtn = array();

        foreach ($data['files'] as $fileName => $file) {
            $fileName = str_replace($this->phpci->buildPath, '', $fileName);

            foreach ($file['messages'] as $message) {
                $rtn[] = array(
                    'file' => $fileName,
                    'line' => $message['line'],
                    'type' => $message['type'],
                    'message' => $message['message'],
                );
            }
        }

        return array($errors, $warnings, $rtn);
    }
}
