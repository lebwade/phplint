<?php

/*
 * This file is part of the overtrue/phplint.
 *
 * (c) 2016 overtrue <i@overtrue.me>
 */

namespace Overtrue\PHPLint\Command;

use Overtrue\PHPLint\Linter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Class LintCommand.
 */
class LintCommand extends Command
{
    /**
     * @var array
     */
    protected $defaults = [
        'jobs' => 5,
        'exclude' => [],
        'extensions' => ['php'],
    ];

    /**
     * @var \Symfony\Component\Console\Input\InputInterface
     */
    protected $input;

    /**
     * @var \Symfony\Component\Console\Output\OutputInterface
     */
    protected $output;

    /**
     * Configures the current command.
     */
    protected function configure()
    {
        $this
            ->setName('phplint')
            ->setDescription('Lint something')
            ->addArgument(
                'path',
                InputArgument::OPTIONAL,
                'Path to file or directory to lint'
            )
            ->addOption(
                'exclude',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Path to file or directory to exclude from linting'
            )
            ->addOption(
                'extensions',
                null,
                InputOption::VALUE_REQUIRED,
                'Check only files with selected extensions (default: php)'
            )
            ->addOption(
                'jobs',
                'j',
                InputOption::VALUE_REQUIRED,
                'Number of parraled jobs to run (default: 5)'
            )
            ->addOption(
                'configuration',
                'c',
                InputOption::VALUE_REQUIRED,
                'Read configuration from config file (default: .phplint.yml).'
            )
            ->addOption(
                'no-configuration',
                null,
                InputOption::VALUE_NONE,
                'Ignore default configuration file (default: .phplint.yml).'
            )
            ->addOption(
                'no-cache',
                null,
                InputOption::VALUE_NONE,
                'Ignore cached data.'
            );
    }

    /**
     * Initializes the command just after the input has been validated.
     *
     * This is mainly useful when a lot of commands extends one main command
     * where some things need to be initialized based on the input arguments and options.
     *
     * @param InputInterface  $input  An InputInterface instance
     * @param OutputInterface $output An OutputInterface instance
     */
    public function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;
    }

    /**
     * Executes the current command.
     *
     * This method is not abstract because you can use this class
     * as a concrete class. In this case, instead of defining the
     * execute() method, you set the code to execute by passing
     * a Closure to the setCode() method.
     *
     * @param InputInterface  $input  An InputInterface instance
     * @param OutputInterface $output An OutputInterface instance
     *
     * @throws LogicException When this abstract method is not implemented
     *
     * @return null|int null or 0 if everything went fine, or an error code
     *
     * @see setCode()
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $startTime = microtime(true);
        $startMemUsage = memory_get_usage(true);

        $output->writeln($this->getApplication()->getLongVersion()." by overtrue and contributors.\n");

        $options = $this->mergeOptions();

        $linter = new Linter($options['path'], $options['exclude'], $options['extensions']);

        $files = $linter->getFiles();
        $fileCount = count($files);

        if ($fileCount <= 0) {
            $output->writeln('<info>Could not find files to lint</info>');

            return 0;
        }

        $linter->setProcessLimit($options['jobs']);

        if (!$input->getOption('no-cache') && file_exists(__DIR__.'/../../.phplint-cache')) {
            $linter->setCache(json_decode(file_get_contents(__DIR__.'/../../.phplint-cache'), true));
        }

        $progress = new ProgressBar($output, $fileCount);
        $progress->setBarWidth(50);
        $progress->setBarCharacter('<info>.</info>');
        $progress->setEmptyBarCharacter('<comment>.</comment>');
        $progress->setProgressCharacter('|');
        $progress->setMessage('', 'filename');
        $progress->setFormat("%filename%\n\n%current%/%max% [%bar%] %percent:3s%% %elapsed:6s%\n");
        $progress->start();

        $linter->setProcessCallback(function ($status, $filename) use ($progress) {
            $progress->setMessage("<info>Checking: </info>{$filename}", 'filename');
            $progress->advance();
        });

        $errors = $linter->lint($files);
        $progress->finish();

        $timeUsage = Helper::formatTime(microtime(true) - $startTime);
        $memUsage = Helper::formatMemory(memory_get_usage(true) - $startMemUsage);

        $code = 0;
        $errCount = count($errors);

        $output->writeln("\nTime: {$timeUsage}, Memory: {$memUsage}MB\n");

        if ($errCount > 0) {
            $output->writeln('<error>FAILURES!</error>');
            $output->writeln("<error>Files: {$fileCount}, Failures: {$errCount}</error>");
            $this->showErrors($errors);
            $code = 1;
        } else {
            $output->writeln("<info>OK! (Files: {$fileCount}, Success: {$fileCount})</info>");
        }

        return $code;
    }

    /**
     * Show errors detail.
     *
     * @param array $errors
     */
    protected function showErrors($errors)
    {
        $i = 0;
        $this->output->writeln("\nThere was ".count($errors).' errors:');

        foreach ($errors as $filename => $error) {
            $this->output->writeln('<comment>'.++$i.". {$filename}:{$error['line']}".'</comment>');
            $error = preg_replace('~in\s+'.preg_quote($filename).'~', '', $error);
            $this->output->writeln("<error> {$error['error']}</error>");
        }
    }

    /**
     * Merge options.
     *
     * @return array
     */
    protected function mergeOptions()
    {
        $options = $this->input->getOptions();
        $options['path'] = $this->input->getArgument('path') ?: './';

        $config = [];

        if (!$this->input->getOption('no-configuration')) {
            $filename = rtrim($options['path'], DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'.phplint.yml';

            if (!$options['configuration'] && file_exists($filename)) {
                $options['configuration'] = realpath($filename);
            }

            if (!empty($options['configuration'])) {
                $this->output->writeln("<comment>Loaded config from \"{$options['configuration']}\"</comment>\n");
                $config = $this->loadConfiguration($options['configuration']);
            }
        }

        $options = array_merge($this->defaults, array_filter($config), array_filter($options));

        is_array($options['extensions']) || $options['extensions'] = explode(',', $options['extensions']);

        return $options;
    }

    /**
     * Load configuration from yaml.
     *
     * @param string $path
     *
     * @return array
     */
    protected function loadConfiguration($path)
    {
        try {
            $configuration = Yaml::parse(file_get_contents($path));
            if (!is_array($configuration)) {
                throw new ParseException('Invalid content.', 1);
            }

            return $configuration;
        } catch (ParseException $e) {
            $this->output->writeln(sprintf('<error>Unable to parse the YAML string: %s<error>', $e->getMessage()));
        }
    }
}
