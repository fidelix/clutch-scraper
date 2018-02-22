<?php
require 'vendor/autoload.php';
require 'AgencyCrawler.php';

$optionList = ['country::', 'offset:', 'csv:', 'metadata', 'list'];
$options = getopt('', $optionList);
if (!isset($options['list']) && !isset($options['metadata'])) {
    exit('You need to specify an action (list or metadata)');
}
if (!empty($options['offset']) && !isset($options['list'])) {
    exit('You cannot use --offset without --list');
}

$config = new \Doctrine\DBAL\Configuration();
$connectionParams = ['url' => 'sqlite:///agencies.sqlite'];
$conn = \Doctrine\DBAL\DriverManager::getConnection($connectionParams, $config);
$agencyCrawler = new AgencyCrawler($conn, new Goutte\Client());
$country = !empty($options['country']) ? $options['country'] : false;

/*$result = $conn->query('SELECT * FROM attribute');
foreach ($result->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $name = trim(preg_replace("/^\d{1,3}%/", "", strip_tags($row['name'])));
    if ($name != $row['name']) {
        $conn->executeUpdate('UPDATE attribute SET name = ? WHERE id = ?', [$name, $row['id']]);
    }
}
exit;*/

// Get list of agencies
if (isset($options['list'])) {
    $offset = isset($options['offset']) ? $options['offset'] : 0;
    $agencyCrawler->saveList($offset, $country);
}
if (isset($options['metadata'])) {
    $agencyCrawler->saveMetadata($country, isset($options['no-cache']));
}
if (isset($options['csv'])) {
    $csv = $options['csv'];
    if (!$csv) {
        $csv = 'agencies.csv';
    }
    $agencyCrawler->saveCSV($options['csv'], $country);
}
print('Fetched all agencies.');
