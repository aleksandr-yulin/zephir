<?php

/*
 * This file is part of the Zephir.
 *
 * (c) Zephir Team <team@zephir-lang.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zephir\Console\Command;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Zephir\BaseBackend;
use Zephir\Compiler;
use Zephir\Config;

use function preg_replace;
use function is_dir;
use function mkdir;
use function extension_loaded;
use function str_replace;
use function trim;
use function strtolower;

/**
 * Zephir\Console\Command\InitCommand.
 *
 * Initializes a Zephir extension.
 */
final class InitCommand extends Command
{
    private $compiler;
    private $backend;
    private $config;
    private $logger;

    public function __construct(Compiler $compiler, BaseBackend $backend, Config $config, LoggerInterface $logger)
    {
        $this->compiler = $compiler;
        $this->backend = $backend;
        $this->config = $config;
        $this->logger = $logger;

        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setName('init')
            ->setDescription('Initializes a Zephir extension')
            ->setDefinition($this->createDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $namespace = $this->sanitizeNamespace($input->getArgument('namespace'));
        $namespacePath = $this->checkNamespacePath($input->getArgument('namespace-path'));
        if (empty($namespacePath)) {
            $namespacePath = $this->checkNamespacePath($namespacePath);
        }

        // Tell the user the name could be reserved by another extension
        if (extension_loaded($namespace)) {
            $this->logger->error('This extension can have conflicts with an existing loaded extension');
        }

        $this->config->set('namespace-paths', [$namespace => $namespacePath]);
        $this->config->set('name', strtolower(str_replace('\\', '_', trim($namespace, '\\'))));

        if (!is_dir($namespacePath)) {
            mkdir($namespacePath, 0755, true);
        }

        // Create 'kernel'
        if (!is_dir('ext/kernel')) {
            mkdir('ext/kernel', 0755, true);
        }

        // Copy the latest kernel files
        $this->recursiveProcess($this->backend->getInternalKernelPath(), 'ext/kernel');

        return 0;
    }

    protected function createDefinition()
    {
        return new InputDefinition(
            [
                new InputArgument(
                    'namespace',
                    InputArgument::REQUIRED,
                    'The extension namespace'
                ),
                new InputOption(
                    'backend',
                    null,
                    InputOption::VALUE_REQUIRED,
                    'Used backend to create extension',
                    'ZendEngine3'
                ),
                new InputArgument(
                    'namespace-path',
                    InputArgument::OPTIONAL,
                    'The extension namespace path'
                ),
            ]
        );
    }

    private function sanitizeNamespace($namespace)
    {
        // Prevent "" namespace
        if (empty($namespace)) {
            throw new RuntimeException('Not enough arguments (missing: "namespace").');
        }

        $namespace = preg_replace('/[^0-9a-zA-Z]+\\\\/', '', $namespace);

        // If sanitizing returns an empty string
        if (empty($namespace)) {
            throw new RuntimeException('Cannot obtain a valid initial namespace for the project.');
        }
        $namespace = trim(str_replace('\/', '\\', $namespace), '\\');
        return $namespace . '\\';
    }

    /**
     * Check valid namespace path
     *
     * @param string|null $path
     *
     * @return string|void|
     */
    private function checkNamespacePath($path)
    {
        if (empty($path) || !is_string($path)) {
            // use default namespace logic if invalid
            return;
        }
        $path = preg_replace('/^\/([A-z0-9-_+]+\/)/', '', $path);
        // If sanitizing returns an empty string
        if (empty($path)) {
            throw new RuntimeException('Cannot obtain a valid initial namespace path for the project.');
        }
        if (strpos($path, 'ext' . DIRECTORY_SEPARATOR) === 0) {
            throw new RuntimeException('Invalid namespace path, root path with name "ext" is reserved');
        }
        return strtolower($path);
    }

    /**
     * Copies the base kernel to the extension destination.
     *
     * @param $src
     * @param $dst
     * @param string $pattern
     * @param mixed  $callback
     *
     * @return bool
     */
    private function recursiveProcess($src, $dst, $pattern = null, $callback = 'copy')
    {
        $success = true;
        $iterator = new \DirectoryIterator($src);

        foreach ($iterator as $item) {
            $pathName = $item->getPathname();
            if (!is_readable($pathName)) {
                $this->logger->error('File is not readable :'.$pathName);
                continue;
            }

            $fileName = $item->getFileName();

            if ($item->isDir()) {
                if ('.' != $fileName && '..' != $fileName && '.libs' != $fileName) {
                    if (!is_dir($dst.\DIRECTORY_SEPARATOR.$fileName)) {
                        mkdir($dst.\DIRECTORY_SEPARATOR.$fileName, 0755, true);
                    }
                    $this->recursiveProcess($pathName, $dst.\DIRECTORY_SEPARATOR.$fileName, $pattern, $callback);
                }
            } elseif (!$pattern || ($pattern && 1 === preg_match($pattern, $fileName))) {
                $path = $dst.\DIRECTORY_SEPARATOR.$fileName;
                $success = $success && \call_user_func($callback, $pathName, $path);
            }
        }

        return $success;
    }
}
