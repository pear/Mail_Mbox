<?php
if (!defined('PHPUnit_MAIN_METHOD')) {
    define('PHPUnit_MAIN_METHOD', 'Mail_Mbox_AllTests::main');
}

if ($fp = @fopen('PHPUnit/Autoload.php', 'r', true)) {
    require_once 'PHPUnit/Autoload.php';
} elseif ($fp = @fopen('PHPUnit/Framework.php', 'r', true)) {
    require_once 'PHPUnit/Framework.php';
} else {
    die('skip could not find PHPUnit');
}
fclose($fp);

require_once dirname(__FILE__) . '/Mail_MboxTest.php';

class Mail_Mbox_AllTests
{
    public static function main()
    {
        if (!function_exists('phpunit_autoload')) {
            require_once 'PHPUnit/TextUI/TestRunner.php';
        }
        PHPUnit_TextUI_TestRunner::run(self::suite());
    }

    public static function suite()
    {
        $suite = new PHPUnit_Framework_TestSuite('Mail_Mbox test suite');
        $suite->addTestSuite('Mail_MboxTest');

        return $suite;
    }
}

if (PHPUnit_MAIN_METHOD == 'Mail_Mbox_AllTests::main') {
    Mail_Mbox_AllTests::main();
}
?>
