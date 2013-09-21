#!/usr/bin/env php
<?php
/**
 * PHPoole is a simple static website generator.
 * @see http://narno.org/PHPoole/
 *
 * @author Arnaud Ligny <arnaud@ligny.org>
 * @license The MIT License (MIT)
 *
 * Copyright (c) 2013 Arnaud Ligny
 */

error_reporting(0); 

use Zend\Console;
use Michelf\Markdown;

// Composer autoloading
if (file_exists('vendor/autoload.php')) {
    $loader = include 'vendor/autoload.php';
}
else {
    echo 'Run the following commands:' . PHP_EOL;
    if (!file_exists('composer.json')) {
        echo 'curl https://raw.github.com/Narno/PHPoole/master/composer.json > composer.json' . PHP_EOL;
    }
    if (!file_exists('composer.phar')) {
        echo 'curl -s http://getcomposer.org/installer | php' . PHP_EOL;
    }  
    echo 'php composer.phar install' . PHP_EOL;
    exit(2);
}

define('PHPOOLE_DIRNAME', '_phpoole');
$websitePath = getcwd();

// Defines rules
$rules = array(
    'help|h'       => 'Get PHPoole usage message',
    'init|i=s'     => 'Build a new website in <website>',
    'generate|g=s' => 'Generate static files of <website>',
    'serve|s=s'    => 'Start Built-in web server with <website> document root',
    'deploy|d=s'   => 'Deploy static <website>',
    'list|l=s'     => 'Lists pages of a <website>'
);

// Get and parse console options
try {
    $opts = new Console\Getopt($rules);
    $opts->parse();
} catch (Console\Exception\RuntimeException $e) {
    echo $e->getUsageMessage();
    exit(2);
}

// help option
if ($opts->getOption('help')) {
    echo $opts->getUsageMessage();
    exit(0);
}

// init option
if ($opts->getOption('init')) {
    $websitePath = getOptionWebsitePath($opts, 'i');
    printf('Initializing new website in %s' . PHP_EOL . PHP_EOL, $websitePath);
    mkInitDir($websitePath);
    mkConfigFile($websitePath . '/' . PHPOOLE_DIRNAME . '/config.ini');
    mkLayoutsDir($websitePath . '/' . PHPOOLE_DIRNAME);
    mkLayoutBaseFile($websitePath . '/' . PHPOOLE_DIRNAME, 'base.html');
    mkAssetsDir($websitePath . '/' . PHPOOLE_DIRNAME);
    mkContentDir($websitePath . '/'. PHPOOLE_DIRNAME);
    mkContentIndexFile($websitePath . '/' . PHPOOLE_DIRNAME, 'index.md');
    mkRouterFile($websitePath . '/' . PHPOOLE_DIRNAME . '/router.php');
    exit(0);
}

// generate option
if ($opts->getOption('generate')) {
    $websitePath = getOptionWebsitePath($opts, 'g');
    printf('Generating website in %s' . PHP_EOL . PHP_EOL, $websitePath);
    $config = getConfig($websitePath . '/' . PHPOOLE_DIRNAME . '/config.ini');
    $twigLoader = new Twig_Loader_Filesystem($websitePath . '/' . PHPOOLE_DIRNAME . '/layouts');
    $twig = new Twig_Environment($twigLoader, array('autoescape' => false));
    $pagesPath = $websitePath . '/' . PHPOOLE_DIRNAME . '/content/pages';
    $markdownIterator = new MarkdownFileFilter($pagesPath);
    foreach ($markdownIterator as $filePage) {
        try {
            if (false === ($content = file_get_contents($filePage->getPathname()))) {
                throw new Exception(sprintf('%s not readable', $filePage->getBasename()));
            }
            $page = parseContent($content, $filePage->getFilename());
        }
        catch (Exception $e) {
            printf('[KO] %s' . PHP_EOL, $e->getMessage());
            exit(2);
        }
        if (isset($page['title'])) {
            $title = $page['title'];
        }
        else {
            $title = 'PHPoole';
        }
        if (
            isset($page['layout'])
            && is_file($websitePath . '/' . PHPOOLE_DIRNAME . '/layouts' . '/' . $page['layout'] . '.html')
        ) {
            $layout = $page['layout'] . '.html';
        }
        else {
            $layout = 'base.html';
        }
        $rendered = $twig->render($layout, array(
            'site'    => $config['site'],
            'author'  => $config['author'],
            'title'   => $title,
            'content' => $page['body']
        ));
        try {
            if (!is_dir($websitePath . '/' . $markdownIterator->getSubPath())) {
                if (false === mkdir($websitePath . '/' . $markdownIterator->getSubPath(), 0777, true)) {
                    throw new Exception(sprintf('%s not created', $websitePath . '/' . $markdownIterator->getSubPath()));
                }
            }
            if (is_file($websitePath . '/' . ($markdownIterator->getSubPath() != '' ? $markdownIterator->getSubPath() . '/' : '') . $filePage->getBasename('.md') . '.html')) {
                if (false === unlink($websitePath . '/' . ($markdownIterator->getSubPath() != '' ? $markdownIterator->getSubPath() . '/' : '') . $filePage->getBasename('.md') . '.html')) {
                    throw new Exception(sprintf('%s%s.html not deleted', ($markdownIterator->getSubPath() != '' ? $markdownIterator->getSubPath() . '/' : ''), $filePage->getBasename('.md')));
                }
                echo '[OK] delete ' . ($markdownIterator->getSubPath() != '' ? $markdownIterator->getSubPath() . '/' : '') . $filePage->getBasename('.md') . '.html' . PHP_EOL;
            }
            if (false === file_put_contents(sprintf('%s%s.html', $websitePath . '/' . ($markdownIterator->getSubPath() != '' ? $markdownIterator->getSubPath() . '/' : ''), $filePage->getBasename('.md')), $rendered)) {
                throw new Exception(sprintf('%s%s.html not written', ($markdownIterator->getSubPath() != '' ? $markdownIterator->getSubPath() . '/' : ''), $filePage->getBasename('.md')));
            }
            printf('[OK] write %s%s.html' . PHP_EOL, ($markdownIterator->getSubPath() != '' ? $markdownIterator->getSubPath() . '/' : ''), $filePage->getBasename('.md'));
        }
        catch (Exception $e) {
            printf('[KO] %s' . PHP_EOL, $e->getMessage());
            exit(2);
        }
    }
    if (is_dir($websitePath . '/assets')) {
        RecursiveRmdir($websitePath . '/assets');
    }
    RecursiveCopy($websitePath . '/' . PHPOOLE_DIRNAME . '/assets', $websitePath . '/assets');
    printf('[OK] copy assets directory' . PHP_EOL);
    exit(0);
}

// serve option
if ($opts->getOption('serve')) {
    $websitePath = getOptionWebsitePath($opts, 's');
    if (version_compare(PHP_VERSION, '5.4.0', '<')) {
        echo 'PHP 5.4+ required to run built-in server (your version: ' . PHP_VERSION . ')' . PHP_EOL;
        exit(2);
    }    
    printf('Start server http://%s:%d' . PHP_EOL . PHP_EOL, 'localhost', '8000');
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $command = sprintf(
            //'START /B php -S %s:%d -t %s %s > nul',
            'START php -S %s:%d -t %s %s > nul',
            'localhost',
            '8000',
            $websitePath,
            sprintf('%s/%s/router.php', $websitePath, PHPOOLE_DIRNAME)
        );
    }
    else {
        echo 'Ctrl-C to stop it.' . PHP_EOL;
        $command = sprintf(
            //'php -S %s:%d -t %s %s >/dev/null 2>&1 & echo $!',
            'php -S %s:%d -t %s %s >/dev/null',
            'localhost',
            '8000',
            $websitePath,
            sprintf('%s/%s/router.php', $websitePath, PHPOOLE_DIRNAME)
        );
    }
    exec($command);
    exit(0);
}

// deploy option
if ($opts->getOption('deploy')) {
    $websitePath = getOptionWebsitePath($opts, 'd');
    $config = getConfig($websitePath . '/' . PHPOOLE_DIRNAME . '/config.ini');
    if (!isset($config['deploy']['repository'])) {
        echo '[KO] no repository in config.ini' . PHP_EOL;
        exit(2);
    }
    else {
        $repoUrl = $config['deploy']['repository'];
    }
    $deployDir = $websitePath . '/../.' . basename($websitePath);
    if (is_dir($deployDir)) {
        echo 'Deploying files to GitHub...' . PHP_EOL
            . PHP_EOL;
        $deployIterator = new FilesystemIterator($deployDir);
        foreach ($deployIterator as $deployFile) {
            if ($deployFile->isFile()) {
                unlink($deployFile->getPathname());
            }
            if ($deployFile->isDir() && $deployFile->getFilename() != '.git') {
                RecursiveRmDir($deployFile->getPathname());
            }
        }
        RecursiveCopy($websitePath, $deployDir);
        $updateRepoCmd = array(
            'add -A',
            'commit -m "Site updated: ' . date('Y-m-d H:i:s') . '"',
            'push github gh-pages --force'
        );
        runGitCmd($deployDir, $updateRepoCmd);
    }
    else {
        echo 'Setting up GitHub deployment...' . PHP_EOL
            . PHP_EOL;
        mkdir($deployDir);
        RecursiveCopy($websitePath, $deployDir);
        $initRepoCmd = array(
            'init',
            'add -A',
            'commit -m "First commit"',
            'branch -M gh-pages',
            'remote add github ' . $repoUrl,
            'push github gh-pages --force'
        );
        runGitCmd($deployDir, $initRepoCmd);
    }
    exit(0);
}

// list option
if ($opts->getOption('list')) {
    $websitePath = getOptionWebsitePath($opts, 'l');
    printf('List content in %s' . PHP_EOL . PHP_EOL, $websitePath);
    if (!is_dir($websitePath . '/' . PHPOOLE_DIRNAME . '/content/pages')) {
        echo 'Invalid content/pages directory' . PHP_EOL
            . PHP_EOL;
        echo $opts->getUsageMessage();
        exit(2);
    }
    $contentIterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($websitePath . '/' . PHPOOLE_DIRNAME . '/content/pages'),
        RecursiveIteratorIterator::CHILD_FIRST
    );    
    foreach($contentIterator as $file) {
        if ($file->isFile()) {
            printf('- %s%s' . PHP_EOL, ($contentIterator->getSubPath() != '' ? $contentIterator->getSubPath() . '/' : ''), $file->getFilename());
        }
    }
    exit(0);
}

// Displays usage message by default
echo $opts->getUsageMessage();
exit(2);


/**
 * PHPoole helpers
 */

function getOptionWebsitePath($opts, $option) {
    if (isset($opts->$option)) {
        if (!is_dir($opts->$option)) {
            echo 'Invalid directory provided' . PHP_EOL
                . PHP_EOL;
            echo $opts->getUsageMessage();
            exit(2);
        }
        $websiteDir = $opts->$option;
        $websitePath = str_replace(DIRECTORY_SEPARATOR, '/', realpath($websiteDir));
        return $websitePath;
    }
}
 
function mkInitDir($path, $force = false) {
    try {
        if (is_dir($path . '/' . PHPOOLE_DIRNAME)) {
            if ($force) {
                RecursiveRmdir($path . '/' . PHPOOLE_DIRNAME);
            }
            else {
                throw new Exception(sprintf('%s directory already exist', PHPOOLE_DIRNAME));
            }
        }
        if (false === mkdir($path . '/' . PHPOOLE_DIRNAME)) {
            throw new Exception(sprintf('%s not created', $path));
        }
        printf('[OK] create %s' . PHP_EOL, PHPOOLE_DIRNAME);
    }  
    catch (Exception $e) {
        printf('[KO] %s' . PHP_EOL, $e->getMessage());
        exit(2);
    }
}

function mkConfigFile($filePath) {
    $content = <<<'EOT'
[site]
name        = "PHPoole"
baseline    = "Light and easy website generator!"
description = "PHPoole is a simple static website/weblog generator written in PHP. It parses your content written with Markdown, merge it with layouts and generates static HTML files."
base_url    = "http://localhost:8000"
language    = "en"
[author]
name  = "Arnaud Ligny"
email = "arnaud+phpoole@ligny.org"
home  = "http://narno.org"
[deploy]
repository = "https://github.com/Narno/PHPoole-demo.git"
EOT;
    try {
        if (false === file_put_contents($filePath, $content)) {
            throw new Exception(sprintf('%s not created', basename($filePath)));
        }
        printf('[OK] create %s/%s' . PHP_EOL, PHPOOLE_DIRNAME, basename($filePath));
    }
    catch (Exception $e) {
        printf('[KO] %s' . PHP_EOL, $e->getMessage());
        exit(2);
    }
}

function mkLayoutsDir($path) {
    try {
        if (false === mkdir(sprintf('%s/layouts', $path))) {
            throw new Exception(sprintf('%s/layouts not created', $path));
        }
        printf('[OK] create %s/layouts' . PHP_EOL, PHPOOLE_DIRNAME);
    }
    catch (Exception $e) {
        printf('[KO] %s' . PHP_EOL, $e->getMessage());
        exit(2);
    }
}

function mkLayoutBaseFile($path, $filename) {
    $subdir = 'layouts';
    $content = <<<'EOT'
<!DOCTYPE html>
<!--[if IE 8]><html class="no-js lt-ie9" lang="{{ site.language }}"><![endif]-->
<!--[if gt IE 8]><!--><html class="no-js" lang="{{ site.language }}"><!--<![endif]-->
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width">
  <title>{{ site.name}} - {{ title }}</title>
  <style type="text/css">
    body { font: bold 24px Helvetica, Arial; padding: 15px 20px; color: #ddd; background: #333;}
    a:link {text-decoration: none; color: #fff;}
    a:visited {text-decoration: none; color: #fff;}
    a:active {text-decoration: none; color: #fff;}
    a:hover {text-decoration: underline; color: #fff;}
  </style>
</head>
<body>
  <strong>{{ site.name }}</strong><br />
  <em>{{ site.baseline }}</em>
  <hr />
  <p>{{ content }}</p>
  <hr />
  <p>Powered by <a href="http://narno.org/PHPoole">PHPoole</a>, coded by <a href="{{ author.home }}">{{ author.name }}</a></p>
</body>
</html>
EOT;
    try {
        if (false === file_put_contents("$path/$subdir/$filename", $content)) {
            throw new Exception(sprintf('%s/%s not created', $subdir, basename($filename)));
        }
        printf('[OK] create %s/%s/%s' . PHP_EOL, PHPOOLE_DIRNAME, $subdir, $filename);
    }
    catch (Exception $e) {
        printf('[KO] %s' . PHP_EOL, $e->getMessage());
        exit(2);
    }
}

function mkAssetsDir($path) {
    $subDirList = array(
        'assets',
        'assets/css',
        'assets/img',
        'assets/js',
    );
    try {
        foreach ($subDirList as $subDir) {
            if (false === mkdir(sprintf('%s/%s', $path, $subDir))) {
                throw new Exception(sprintf('%s/%s not created', $path, $subDir));
            }
            printf('[OK] create %s/%s' . PHP_EOL, PHPOOLE_DIRNAME, $subDir);
        }
    }
    catch (Exception $e) {
        printf('[KO] %s' . PHP_EOL, $e->getMessage());
        exit(2);
    }
}

function mkContentDir($path) {
    $subDirList = array(
        'content',
        'content/posts',
        'content/pages',
    );
    try {
        foreach ($subDirList as $subDir) {
            if (false === mkdir(sprintf('%s/%s', $path, $subDir))) {
                throw new Exception(sprintf('%s/%s not created', $path, $subDir));
            }
            printf('[OK] create %s/%s' . PHP_EOL, PHPOOLE_DIRNAME, $subDir);
        }
    }
    catch (Exception $e) {
        printf('[KO] %s' . PHP_EOL, $e->getMessage());
        exit(2);
    }
}

function mkContentIndexFile($path, $filename) {
    $subdir = 'content/pages';
    $content = <<<'EOT'
<!--
title = PHPoole
layout = base
-->
Welcome!
========

PHPoole is a simple static website/weblog generator written in PHP.
It parses your content written with Markdown, merge it with layouts and generates static HTML files.

PHPoole = [PHP](http://www.php.net) + [Poole](http://en.wikipedia.org/wiki/Strange_Case_of_Dr_Jekyll_and_Mr_Hyde#Mr._Poole)

Go to the [dedicated website](http://narno.org/PHPoole) for more details.
EOT;
    try {
        if (false === file_put_contents("$path/$subdir/$filename", $content)) {
            throw new Exception(sprintf('%s/%s not created', $subdir, basename($filename)));
        }
        printf('[OK] create %s/%s/%s' . PHP_EOL, PHPOOLE_DIRNAME, $subdir, $filename);
    }
    catch (Exception $e) {
        printf('[KO] %s' . PHP_EOL, $e->getMessage());
        exit(2);
    }
}

function mkRouterFile($filePath) {
    $content = <<<'EOT'
<?php
date_default_timezone_set("UTC");
define("DIRECTORY_INDEX", "index.html");
$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
$ext = pathinfo($path, PATHINFO_EXTENSION);
if (empty($ext)) {
    $path = rtrim($path, "/") . "/" . DIRECTORY_INDEX;
}
if (file_exists($_SERVER["DOCUMENT_ROOT"] . $path)) {
    return false;
}
http_response_code(404);
echo "404, page not found";
EOT;
    try {
        if (is_file($filePath)) {
            unlink($filePath);
        }
        if (false === file_put_contents($filePath, $content)) {
            throw new Exception(sprintf('%s not created', basename($filename)));
        }
        printf('[OK] create %s/%s' . PHP_EOL, PHPOOLE_DIRNAME, basename($filePath));
    }
    catch (Exception $e) {
        printf('[KO] %s' . PHP_EOL, $e->getMessage());
        exit(2);
    }
}

function parseContent($content, $filename) {
    preg_match('/^<!--(.+)-->(.+)/s', $content, $matches);
    if (!$matches) {
        printf('Could not parse front matter in %s' . PHP_EOL, $filename);
        exit(2);
    }
    list($matchesAll, $rawInfo, $rawBody) = $matches;
    $info = parse_ini_string($rawInfo);
    $body = Markdown::defaultTransform($rawBody);
    return array_merge(
        $info,
        array('body' => $body)
    );
}

function getConfig($filename) {
    return parse_ini_file($filename, true);
}

function runGitCmd($wd, $commands) {
    chdir($wd);
    exec('git config core.autocrlf false');
    foreach ($commands as $cmd) {
        printf('git %s' . PHP_EOL, $cmd);
        exec(sprintf('git %s', $cmd));
    }
}


/**
 * Utils
 */

/**
 * Recursively remove a directory
 *
 * @param string $dirname
 * @param boolean $followSymlinks
 * @return boolean
 */
function RecursiveRmdir($dirname, $followSymlinks=false) {
    if (is_dir($dirname) && !is_link($dirname)) {
        if (!is_writable($dirname)) {
            throw new Exception(sprintf('%s is not writable!', $dirname));
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dirname),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        while ($iterator->valid()) {
            if (!$iterator->isDot()) {
                if (!$iterator->isWritable()) {
                    throw new Exception(sprintf(
                        '%s is not writable!',
                        $iterator->getPathName()
                    ));
                }
                if ($iterator->isLink() && $followLinks === false) {
                    $iterator->next();
                }
                if ($iterator->isFile()) {
                    unlink($iterator->getPathName());
                }
                elseif ($iterator->isDir()) {
                    rmdir($iterator->getPathName());
                }
            }
            $iterator->next();
        }
        unset($iterator);
 
        return rmdir($dirname);
    }
    else {
        throw new Exception(sprintf('%s does not exist!', $dirname));
    }
}

/**
 * Copy a dir, and all its content from source to dest
 */
function RecursiveCopy($source, $dest) {
    if (!is_dir($dest)) {
        mkdir($dest);
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isDir()) {
            mkdir($dest . DIRECTORY_SEPARATOR . $iterator->getSubPathName());
        }
        else {
            copy($item, $dest . DIRECTORY_SEPARATOR . $iterator->getSubPathName());
        }
    }
}

/**
 * Markdown file iterator
 */
class MarkdownFileFilter extends FilterIterator
{
    public function __construct($dirOrIterator = '.')
    {
        if (is_string($dirOrIterator)) {
            if (!is_dir($dirOrIterator)) {
                throw new Exception\InvalidArgumentException('Expected a valid directory name');
            }
            $dirOrIterator = new RecursiveDirectoryIterator($dirOrIterator, RecursiveDirectoryIterator::FOLLOW_SYMLINKS);
        }
        elseif (!$dirOrIterator instanceof DirectoryIterator) {
            throw new Exception\InvalidArgumentException('Expected a DirectoryIterator');
        }
        if ($dirOrIterator instanceof RecursiveIterator) {
            $dirOrIterator = new RecursiveIteratorIterator($dirOrIterator);
        }
        parent::__construct($dirOrIterator);
    }

    public function accept()
    {
        $file = $this->getInnerIterator()->current();
        if (!$file instanceof SplFileInfo) {
            return false;
        }
        if (!$file->isFile()) {
            return false;
        }
        if ($file->getExtension() == 'md') {
            return true;
        }
    }
}