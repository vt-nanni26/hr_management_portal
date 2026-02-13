<?php
require 'send_email.php';

echo sendEmail(
    'vt.nanni26@gmail.com',
    'Final Test',
    '<b>Email works</b>'
) ? 'SENT' : 'FAILED';
