<?php
use Goutte\Client;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Class AgencyCrawler
 */
class AgencyCrawler
{
    public $DB;
    public $client;
    public $siteListUri = "https://clutch.co/web-developers?sort_bef_combine=title+ASC";
    public $siteNodeUri = "https://clutch.co/node/";
    public function __construct(\Doctrine\DBAL\Connection $dbConn, Client $client)
    {
        $this->DB = $dbConn;
        $this->client = $client;
    }

    public function saveList($offset, $country)
    {
        $uri = $this->siteListUri;
        if ($country) {
            $sql = 'SELECT code FROM country WHERE name = ?';
            $result = $this->DB->fetchAssoc($sql, [$country]);
            $uri .= "&country={$result['code']}";
        }
        // Get the first page. Agencies in this page are always collected.
        $firstPage = $this->client->request('GET', $uri);
        // Get total number of pages
        $pagerNode = $firstPage->filter('.view-display-id-directory .pager');
        $pagerText = $pagerNode->filter('.pager-current')->text();
        if (!preg_match("/\d+ of (\d+)/", $pagerText, $matches)) {
            exit('Could not determine total page count');
        }
        $pageTotal = $matches[1];
        print "Total Pages: $pageTotal\n";
        for ($i = 1 + $offset; $i <= $pageTotal; $i++) {
            print "Crawling page $i of $pageTotal\n";
            if ($i === 1) {
                $page = $firstPage;
            } else {
                $pageNumber = $i - 1;
                $page = $this->client->request('GET', "${uri}&page=$pageNumber");
            }
            $agencyNodes = $page->filter('.view-display-id-directory .provider-row > .row')
                ->reduce(function (Crawler $node, $i) {
                    // Remove sponsored entries
                    if ($node->filter('span.sponsored')->count()) {
                        return false;
                    }
                    return true;
                });
            $agencyData = $agencyNodes->each(function (Crawler $node) {
                $id = $node->attr('data-clutch-nid');
                $name = $node->filter('.company-name a')->text();
                $localityNode = $node->filter('.location-city .locality');
                $locality = null;
                if ($localityNode->count()) {
                    $locality = trim(trim($localityNode->text()), ',');
                }
                $country = $node->filter('.location-country .country-name')->text();
                $website = $node->filter('.website-link a')->attr('href');
                return [
                    'id' => $id,
                    'name' => $name,
                    'locality' => $locality,
                    'country' => $country,
                    'website' => $website
                ];
            });
            $sql = 'INSERT OR IGNORE INTO agency(id, name, locality, country, website)
              VALUES(?, ?, ?, ?, ?)';
            foreach ($agencyData as $agency) {
                $this->DB->executeQuery($sql, [
                    $agency['id'],
                    $agency['name'],
                    $agency['locality'],
                    $agency['country'],
                    $agency['website']
                ]);
            }
        }
    }

    public function saveMetadata($country, $noCache = false)
    {
        $sql = 'SELECT id, name, employees, locality, country FROM agency';
        if ($country) {
            $sql .= ' WHERE country = ?';
        }
        if (!$noCache && !$country) {
            $sql .= " WHERE employees IS NULL OR employees = ''";
        } elseif (!$noCache && $country) {
            $sql .= " AND (employees IS NULL OR employees = '')";
        }
        $stmt = $this->DB->executeQuery($sql, [$country]);
        // Process each agency from DB.
        $agencyList = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $count = count($agencyList);
        $currentNumber = 0;
        print  $count . " agencies to be updated. \n";
        foreach ($agencyList as $row) {
            $currentNumber++;
            print "$currentNumber of $count. ";
            $agencyId = $row['id'];
            $page = $this->client->request('GET', $this->siteNodeUri . '/' . $agencyId);
            $jsNode = $page->filter('script')->last();
            if (!$jsNode->count()) {
                trigger_error("Could not find JS Data for $agencyId - {$row['name']}", E_USER_ERROR);
                continue;
            }
            $jsString = $jsNode->text();
            if (!preg_match('/jQuery.extend\(Drupal\.settings, (.+)\);/', $jsString, $match)) {
                trigger_error("Could not find JS Data for $agencyId - {$row['name']}", E_USER_ERROR);
                continue;
            }
            $jsData = json_decode($match[1]);
            foreach ($jsData->clutchGraph as $focus) {
                $type = $page->filter('#' . $focus->element_id)->previousAll()->text();
                foreach ($focus->dataset as $set) {
                    // Clean up label with regex and save it in the DB.
                    $name = trim(preg_replace("/^\d{1,3}%/", "", strip_tags($set->label)));
                    $sql = 'INSERT OR IGNORE INTO attribute(name, type) VALUES (?, ?)';
                    $this->DB->executeQuery($sql, [$name, $type]);
                    $sql = 'SELECT id FROM attribute WHERE name = ? AND type = ?';
                    $stmt = $this->DB->executeQuery($sql, [$name, $type]);
                    $attrId = $stmt->fetch(PDO::FETCH_ASSOC)['id'];

                    $sql = 'INSERT OR IGNORE INTO focus(agencyId, attrId, attrPercent)
                      VALUES (:agencyId, :attrId, :attrPercent)';
                    $this->DB->executeQuery($sql, [
                        ':agencyId' => $agencyId,
                        ':attrId' => $attrId,
                        ':attrPercent' => intval($set->value)
                    ]);
                    $sql = 'UPDATE focus SET attrPercent = :attrPercent
                      WHERE agencyId = :agencyId AND attrId = :attrId';
                    $this->DB->executeQuery($sql, [
                        ':agencyId' => $agencyId,
                        ':attrId' => $attrId,
                        ':attrPercent' => intval($set->value)
                    ]);
                }
            }

            $xpath = "//*[@property='address'][.//*[@property='schema:addressLocality'][text()='{$row['locality']}']]";
            $addressNode = $page->filterXPath($xpath);
            if (!$addressNode->count()) {
                // No city. Try country.
                $xpath = "//*[@property='address'][.//*[@property='schema:addressCountry']"
                    . "[text()='{$row['country']}']]";
                $addressNode = $page->filterXPath($xpath);
            }
            $postCode = null;
            $postCodeNode = $addressNode->filter('[property="schema:postalCode"]');
            $postCode = $postCodeNode->count() ? $postCodeNode->text() : null;
            $telephoneNode = $addressNode->filter('[property="schema:telephone"]');
            $telephone = $telephoneNode->count() ? $telephoneNode->text() : null;
            $employees = trim($page->filter('.field-name-field-pp-size-people .field-item')->text());
            $sql = 'UPDATE agency SET employees = ?, postCode = ?, telephone = ? WHERE id = ?';
            $this->DB->executeQuery($sql, [
                $employees,
                $postCode,
                $telephone,
                $agencyId
            ]);
            print "Updated {$row['name']}.\n";
        }
    }
    public function saveCSV($path, $country)
    {
        exit();
        // Build the headers.
        $headers = [
            'ID', 'Name', 'Employees', 'City', 'Post Code', 'Country',
            'Website', 'Telephone'];
        $niceHosting = ['Amazon', 'Heroku', 'Google app engine'];
        $niceDev = [
            'Web Development',
            'Drupal', 'Laravel', 'Symfony', 'Wordpress', 'WooCommerce',
            'Ubercart', 'CakePHP', 'Zend', 'Magento', 'Shopify', 'PHP',
            'Python', 'Ruby', 'Ruby on Rails', 'Django'
        ];

        $bigArray = [];
        $fp = fopen($path, 'w');
        /*fputcsv($fp, [
            'ID',
            ''
        ]);*/
        $sql = 'SELECT id, name, employees, locality, postCode, country, website, telephone
          FROM agency ';
        if ($country) {
            $sql .= ' WHERE country = ?';
        }
        $stmt = $this->DB->executeQuery($sql, [$country]);
        // Process each agency from DB.
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            // Get all attributes.
            $sql = <<<SQL
SELECT attrPercent, name, type
FROM focus
INNER JOIN attribute ON focus.attrId = attribute.id
WHERE focus.agencyId = ?
SQL;
            $attributes = $this->DB->fetchAll($sql, [$row['id']]);
            foreach ($attributes as $attribute) {
                $row[$attribute['name']] = $attribute['attrPercent'];
            }
            $bigArray[$row['id']] = $row;
        }
        fclose($fp);
    }
}
