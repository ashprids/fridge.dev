<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/sitemap.php';
header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=300');
header('X-Robots-Tag: noindex');
echo fridge_sitemap_xml(dirname(__DIR__));
