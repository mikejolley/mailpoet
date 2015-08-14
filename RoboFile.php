<?php

class RoboFile extends \Robo\Tasks {
  function install() {
    $this->_exec('./composer.phar install');
    $this->_exec('npm install');
  }

  function update() {
    $this->say(getenv('WP_TEST_URL'));
    $this->_exec('./composer.phar update');
    $this->_exec('npm update');
  }

  function watch() {
    $css_files = array(
      'assets/css/src/admin.styl',
      'assets/css/src/rtl.styl'
    );

    $js_files = glob('assets/js/src/*.js');

    $this->taskWatch()
      ->monitor($js_files, function() {
        $this->compileJavascript();
      })
      ->monitor($css_files, function() use($css_files) {
        $this->compileStyles($css_files);
      })
      ->run();
  }

  function compileJavascript() {
    $this->_exec('./node_modules/webpack/bin/webpack.js');
  }

  protected function compileStyles($files = array()) {
    if(empty($files)) { return; }

    $this->_exec(join(' ', array(
      './node_modules/stylus/bin/stylus',
      '-u nib',
      '-w',
      join(' ', $files),
      '-o assets/css/'
    )));
  }

  function makepot() {
    $this->_exec('grunt makepot'.
                ' --gruntfile '.__DIR__.'/tasks/makepot/makepot.js'.
                ' --base_path '.__DIR__
    );
  }

  function pushpot() {
    $this->_exec('grunt pushpot'.
                ' --gruntfile '.__DIR__.'/tasks/makepot/makepot.js'.
                ' --base_path '.__DIR__
    );
  }

  function testUnit($singleUnit = null) {
    $this->loadEnv();
    $this->_exec('vendor/bin/codecept build');
    $this->_exec('vendor/bin/codecept run unit ' . (($singleUnit) ? $singleUnit : ''));
  }

  function testAcceptance() {
    $this->loadEnv();
    $this->_exec('vendor/bin/codecept build');
    $this
      ->taskExec('phantomjs --webdriver=4444')
      ->background()
      ->run();
    sleep(2);
    $this->_exec('vendor/bin/codecept run acceptance');
  }

  function testJavascript() {
    $this->compileJavascript();

    $this->_exec(join(' ', array(
      './node_modules/mocha/bin/mocha',
      '-r tests/javascript/mochaTestHelper.js',
      'tests/javascript/testBundles/**/*.js'
    )));
  }

  function testAll() {
    $this->loadEnv();
    $this->_exec('vendor/bin/codecept build');
    $this
      ->taskexec('phantomjs --webdriver=4444')
      ->background()
      ->run();
    sleep(2);
    $this->_exec('vendor/bin/codecept run');
  }

  function testDebug() {
    $this->_exec('vendor/bin/codecept build');
    $this->loadEnv();
    $this->_exec('vendor/bin/codecept run unit --debug');
  }

  function zip() {
    $rootPath = realpath('wysija-newsletters');
    $zip = new ZipArchive();
    $zip->open('mailpoet.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $directory = new \RecursiveDirectoryIterator(
      $rootPath, \FilesystemIterator::FOLLOW_SYMLINKS
    );
    $filter = new \RecursiveCallbackFilterIterator(
      $directory, function ($current, $key, $iterator) {
        return ($current->getFilename()[0] !== '.');
      }
    );
    $files = new \RecursiveIteratorIterator($filter);
    foreach ($files as $name => $file) {
      $filePath = $file->getRealPath();
      $relativePath = substr($filePath, strlen($rootPath) + 1);
      $zip->addFile($filePath, $relativePath);
    }
    $zip->close();
  }

  function build() {
    $this->_exec('mkdir wysija-newsletters');
    $this->_exec('mkdir wysija-newsletters/vendor');
    $this->_exec('cp index.php wysija-newsletters/index.php');
    $this->_exec('cp mailpoet.php wysija-newsletters/mailpoet.php');
    $this->_exec('cp package.json wysija-newsletters/package.json');
    $this->_exec('cp composer.* wysija-newsletters/');
    $this->taskCopyDir(array(
      'assets' => 'wysija-newsletters/assets',
      'lang' => 'wysija-newsletters/lang',
      'lib' => 'wysija-newsletters/lib',
      'views' => 'wysija-newsletters/views'
    ))->run();
    chdir('./wysija-newsletters');
    $this->taskExecStack()
      ->stopOnFail()
      // ->exec('cd wysija-newsletters') does not work as intended
      ->exec('./composer.phar install --no-dev')
      ->run();
    chdir('..');
    $this->_exec('rm wysija-newsletters/package.json');
    $this->_exec('rm wysija-newsletters/composer.*');
    $this->zip();
    $this->taskDeleteDir('wysija-newsletters')->run();
  }

  protected function loadEnv() {
    $dotenv = new Dotenv\Dotenv(__DIR__);
    $dotenv->load();

    $this
      ->taskWriteToFile('tests/acceptance.suite.yml')
      ->textFromFile('tests/acceptance.suite.src')
      ->run();

    $this
      ->taskReplaceInFile('tests/acceptance.suite.yml')
      ->regex("/url.*/")
      ->to('url: ' . "'" . getenv('WP_TEST_URL'). "'")
      ->run();
  }
}
