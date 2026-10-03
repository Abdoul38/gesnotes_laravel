<?php
// Serveur de démonstration sur la base fictive gesnotes_demo (http://udh.localhost:8011).
chdir(__DIR__.'/../..');
putenv('UNI_UDH_DB=gesnotes_demo');
passthru(PHP_BINARY.' artisan serve --host=127.0.0.1 --port=8011');
