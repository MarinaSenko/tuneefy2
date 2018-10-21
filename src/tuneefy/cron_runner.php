<?php

require dirname(__FILE__).'/../../vendor/autoload.php';

$commands = [
  tuneefy\Command\StatsUpdaterCommand::class,
  tuneefy\Command\ExpiredIntentsCleanerCommand::class,
];

echo 'Starting at '.date(DATE_RFC2822)."\n";

foreach ($commands as $command) {
    $classpath = explode('\\', $command);
    echo 'Running command : '.end($classpath).' ... ';

    $startingAt = microtime(true);
    try {
        $result = $command::run();
        echo "[\033[01;32mOK\033[0m]";
    } catch (\Exception $e) {
        echo "[\033[01;31mFailed\033[0m]";
        echo "\n→ \033[01;33mError: \033[0m".$e->getMessage();
    }

    $elapsed = microtime(true) - $startingAt;
    printf(' (%dmin %ds %dms)', (int) ($elapsed / 60), (int) $elapsed, (int) (($elapsed - (int) $elapsed) * 1000));
    echo "\n";
}

echo "\033[01;32mDone\033[0m, exiting.\n";
