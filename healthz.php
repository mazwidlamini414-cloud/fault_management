<?php
// Railway healthcheck endpoint — must return HTTP 200
// No DB dependency, no redirects, no session
http_response_code(200);
header('Content-Type: text/plain');
echo 'OK';
exit;

