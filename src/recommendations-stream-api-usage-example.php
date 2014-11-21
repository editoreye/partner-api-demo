<?php
/**
 * Created by JetBrains PhpStorm.
 * User: sjc
 * Date: 10/10/2013
 * Time: 10:49
 */

class RecommendationsApiLoader
{
    /**
     * The endpoint for the API service
     *
     * @var string
     */
    protected $serviceEndpoint = "http://partner-api.editoreye.com/recommendations/stream.xml";

    /**
     * The install ID
     *
     * @var int
     */
    protected $installId;

    /**
     * The API key, used for authentication
     *
     * @var string
     */
    protected $apiKey;

    /**
     * Location of file to store the last action ID
     *
     * @var string
     */
    protected $lastIdFile = "/tmp/se-api-recommendations-lastId";

    /**
     * Directory where we'll store the raw recommendations data in lieu of a database for this example
     *
     * @var string
     */
    protected $recommendationStoreDirectory = "/tmp/se-api-recommendations-store";

    /**
     * File where we'll log activity
     *
     * @var string
     */
    protected $logFile = "/tmp/se-api-process-log";

    protected $logHandle;

    /**
     * Check that some directories/files which we'll use for storage exist, opens log file
     *
     * @param $installId
     * @param $apiKey
     * @throws Exception
     */
    public function __construct($installId, $apiKey)
    {
        $this->installId = $installId;
        $this->apiKey = $apiKey;

        if (! file_exists($this->recommendationStoreDirectory)) {
            mkdir($this->recommendationStoreDirectory);
        }

        if (! is_writable($this->recommendationStoreDirectory)) {
            throw new Exception('Cannot write into configured article store directory');
        } else if (file_exists($this->lastIdFile) && ! is_writable($this->lastIdFile)) {
            throw new Exception('Cannot write into configured last ID file');
        } else if (file_exists($this->logFile) && ! is_writable($this->logFile)) {
            throw new Exception('Configured process log file exists, but cannot write to it');
        }

        // open the log file
        $this->logHandle = fopen($this->logFile,'a');
    }

    /**
     * Tidy up - close the log file
     */
    public function __destruct()
    {
        fclose($this->logHandle);
    }

    /**
     * This is the main method
     *
     * @throws Exception
     */
    public function execute()
    {
        $lastId = $this->getLastId();
        $actionCounter = 0;
        do {
            // call the API using curl
            $serviceUrl = $this->getApiUrl($lastId,10);
            $this->log(sprintf('calling service at: %s',$serviceUrl));
            $curl = curl_init($serviceUrl);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            $curl_response = curl_exec($curl);
            curl_close($curl);

            // parse the response into a DOMDocument
            $doc = new DOMDocument();
            if (! $doc->loadXML($curl_response)) {
                throw new Exception("Unable to load XML into DOMDocument");
            }

            $xpath = new DOMXPath($doc);

            // check for any actions, and process them
            $actionNodes = $xpath->query('actions/action');
            foreach ($actionNodes as $actionNode) {
                $this->processAction($actionNode);
                echo ".";
                $actionCounter++;
            }

            // check for a query-continue block, and if found load the lastId (and optionally limit) from it
            $queryContinueNodes = $xpath->query('query-continue');
            if ($queryContinueNodes->length == 1) {
                $queryContinueNode = $queryContinueNodes->item(0);
                $lastIdNodes = $xpath->query("param[@name = 'lastId']",$queryContinueNode);
                if ($lastIdNodes->length != 1) {
                    throw new Exception("Invalid query-continue block: does not contain exactly one lastId parameter");
                }
                $lastId = $lastIdNodes->item(0)->nodeValue;
                $this->recordLastId($lastId);
            }

            echo sprintf("\nProcessed %d actions\n",$actionCounter);

            // continue if we had found a query-continue block
        } while ($queryContinueNodes->length > 0);
    }

    /**
     * Simple logger, prepending date/time
     *
     * @param $message
     */
    protected function log($message)
    {
        $msg = sprintf("[%s] %s\n",date('Y-m-d H:i:s'),$message);
        fwrite($this->logHandle,$msg,strlen($msg));
    }

    /**
     * Construct the API URL using the provided parameters
     *
     * @param null $lastId
     * @param int $limit
     * @return string
     */
    protected function getApiUrl($lastId = null, $limit = 10)
    {
        $queryData = array(
            'key' => $this->apiKey,
            'install' => $this->installId
        );

        if (! is_null($lastId)) {
            $queryData['lastId'] = $lastId;
        }

        if (! is_null($limit) && is_numeric($limit)) {
            $queryData['limit'] = $limit;
        }

        return sprintf('%s?%s',$this->serviceEndpoint,http_build_query($queryData));
    }

    /**
     * Retrieve the lastId from the data store; in this crude example it's being stored in a file
     *
     * @return int|null An integer if a lastId has been recorded, else null
     */
    protected function getLastId()
    {
        if (file_exists($this->lastIdFile)) {
            $data = file_get_contents($this->lastIdFile);
            $this->log(sprintf('loaded last id from file: %d',$data));
            return (int) $data;
        } else {
            $this->log(sprintf('no last id file present'));
            return null;
        }
    }

    /**
     * Record the last action ID into the datastore, in this example a flat file
     *
     * @param $lastId
     */
    protected function recordLastId($lastId)
    {
        $this->log(sprintf('recording last id: %d',$lastId));
        file_put_contents($this->lastIdFile,$lastId);
    }

    /**
     * Validation on the action node, then decided what to do based on the type attribute
     *
     * @param DOMElement $actionNode
     * @throws Exception
     */
    protected function processAction(DOMElement $actionNode)
    {
        $actionId = $actionNode->getAttribute('actionId');
        $actionType = $actionNode->getAttribute('type');

        $articleNodes = $actionNode->getElementsByTagName('article');
        if ($articleNodes->length != 1) {
            throw new Exception(sprintf("Invalid action node #%d: does not contain exactly one article child node",$actionId));
        }
        $articleNode = $articleNodes->item(0);
        $articleId = $articleNode->getAttribute('articleId');

        // log the action being processed
        $message = sprintf(
            'action: %d, action type: %s, article id: %d',
            $actionId,
            $actionType,
            $articleId
        );
        $this->log($message);

        switch ($actionType) {
            case 'recommend':
                // a recommend can indicate a newly recommended article, or an update to one
                $this->recommendArticle($articleNode);
                break;

            case 'unrecommend':
                // an unrecommend indicates that the recommended article should be removed
                $this->unrecommendArticle($articleId);
                break;

            default:
                throw new Exception("Unexpected action type encountered: " . $actionType);
                break;
        }
    }

    /**
     * Stores the article into the database; checks first to see if this is an update based on the articleId
     *
     * @param DOMElement $articleNode
     */
    protected function recommendArticle(DOMElement $articleNode)
    {
        $articleId = $articleNode->getAttribute('articleId');

        $filePath = sprintf('%s/%s.xml',$this->recommendationStoreDirectory,$articleId);

        // check for existing record
        $recordExists = file_exists($filePath);

        // update/insert record; in this case we just overwrite the file each time; in a real-world case you would
        // extract some of the metadata and store that separately for lookups
        file_put_contents($filePath,$articleNode->ownerDocument->saveXML($articleNode));

        if ($recordExists) {
            $this->log(sprintf(' - updated article %d',$articleId));
        } else {
            $this->log(sprintf(' - added article %d',$articleId));
        }
    }

    /**
     * Unpublish article - sets record as unpublished or deletes it
     *
     * @param $articleId
     */
    protected function unrecommendArticle($articleId)
    {
        $filePath = sprintf('%s/%s.xml',$this->recommendationStoreDirectory,$articleId);

        // check for existing record
        $recordExists = file_exists($filePath);

        // mark as unpublished/delete
        if ($recordExists) {
            unlink($filePath);

            $this->log(sprintf(' - removed article %d',$articleId));
        } else {
            $this->log(sprintf(' - cannot remove article %d, not present in store',$articleId));

        }
    }
}

$installId = 0; // your install ID
$apiKey = ""; // your API key
$loader = new RecommendationsApiLoader($installId,$apiKey);
$loader->execute();
