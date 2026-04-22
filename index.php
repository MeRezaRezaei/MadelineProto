<?php

use danog\MadelineProto\API;

require 'vendor/autoload.php';


$a = new API('/tmp/as.madeline');

$a->start();