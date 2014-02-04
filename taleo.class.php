<?php 
class TaleoClient {

  protected $companyid,
            $username,
            $password,
            $client,
            $dispatcher,
            $dispatcherWSDL,
            $webWSDL,
            $sessionid,
            $raw = NULL;

  public function __construct($companyid, $username, $password, $dispatcherWSDL, $webWSDL) {
    $this->companyid = $companyid;
    $this->username = $username;
    $this->password = $password;
    $this->dispatcherWSDL = $dispatcherWSDL;
    $this->webWSDL = $webWSDL;


    $this->authenticate();
  }
  
  public function setBinaryResume($candidateID, $filename, $resume_content) {
    try {
        $this->client->setBinaryResume($this->sessionid, $candidateID, $filename, array('array' => $resume_content));
    } catch (Exception $e) {
      $this->saveLastRequest();
      throw $e;
    }
    
    $this->saveLastRequest();
  }

  public function upsertCandidateToRequisition($candidateID, $requisitionID) {
   try {
     $this->client->upsertCandidateToRequisitions($this->sessionid, $candidateID, array('array' => array('item' => $requisitionID)), 2, 0, FALSE);
    }
    catch (Exception $e) {
      $this->saveLastRequest();
      throw $e;
    }
    
    $this->saveLastRequest();
  }
  
  public function createCandidate($candidate, $source = 'Careers Website') {
    try {
 

      $education = 'Educational Institute: '. (($candidate->education != 'Other') ?
                                                $candidate->education :
                                                $candidate->educationOther);
      $additionalLanguages = 'Additional Languages Spoken: '. $candidate->otherLanguages;
      $geography = 'Geographies: '. implode(', ', array_keys(array_filter($candidate->geography)));
      $functionalGroup = 'Functional Groups: '. implode(', ', array_keys(array_filter($candidate->functionalGroup)));
      $willingToRelocate = 'Willing to Relocate: '. $candidate->willingToRelocate;
      $salaryDenomination = 'Salary Denomination: '. $candidate->otherSalaryDenomination;
      $summary = implode("\n\n", array($education, $additionalLanguages, $willingToRelocate, $geography, $functionalGroup, $salaryDenomination));

      $candidateID = $this->client->createCandidate($this->sessionid, array(
        'status' => 'NEW',
        'email' => $candidate->email,
        'lastName' => $candidate->lastName,
        'firstName' => $candidate->firstName,
        'address' => $candidate->address,
        'city' => $candidate->city,
        'country' => $candidate->country,
        'state' => $candidate->state,
        'zipCode' => $candidate->zipCode,
        'phone' => $candidate->phone,
        'legalStatus' => $candidate->legalStatus,
        'race' => $candidate->race,
        'gender' => $candidate->gender,
        'referredBy' => $candidate->your_name,
        'id' => '',
        'rank' => '',
        'textResume' => $candidate->textResume,
        'source' => $source,
        'flexValues' => array(
          array(
            'fieldName' => 'Languages Spoken',
            'valueStr' => implode('|', array_keys(array_filter($candidate->languages))),
          ),
          array(
            'fieldName' => 'Candidate Summary',
            'valueStr' => $summary,
          ),
          array(
            'fieldName' => 'educationLevel',
            'valueStr' => $candidate->educationLevel,
          ),
          array(
            'fieldName' => 'Current Salary',
            'valueDbl' => floatval(preg_replace('/[^0-9.]/', '', $candidate->Current_Salary)),
          ),
          array(
            'fieldName' => 'Other (Specify Source)',
            'valueStr' => $candidate->howdidyouhear .' ( '. $candidate->howdidyouhearSpecific .' )',
          ),
          array(
            'fieldName' => 'educationLevel',
            'valueStr' => $candidate->degree,
          ),
          array(
            'fieldName' => 'Current Salary Currency',
            'valueStr' => (($candidate->salaryDenomination != 'Other') ? str_replace(' ', "\xc2\xa0", $candidate->salaryDenomination) : ''),
          ),
          array(
            'fieldName' => 'Willing to Travel',
            'valueStr' => $candidate->willingToTravel,
          ),
          array(
            'fieldName' => 'Current Location',
            'valueStr' => $candidate->currentLocation),
          array(
            'fieldName' => "Referrer's E-mail Address",
            'valueStr' => $candidate->referrerEmail),
          array(
            'fieldName' => 'Nationality',
            'valueStr' => $candidate->nationality),
//          array(
//            'fieldName' => 'Cluster',
//            'valueStr' => $candidate->cluster),
//          ),
          array(
            'fieldName' => 'Current Availability',
            'valueStr' => (($candidate->availability != 'Other') ?
                            $candidate->availability : $candidate->availabilityOther),
          ),
        )));

      } catch (Exception $e) {
        $this->saveLastRequest();
        throw $e->getMessage();
      }
      
      $this->saveLastRequest();
      return $candidateID;
  }

  protected function authenticate() {
    $this->dispatcher = new SoapClient($this->dispatcherWSDL, array(
                'exceptions' => TRUE));
    $webURL = $this->dispatcher->getURL($this->companyid);



    $this->client = new SoapClient($this->webWSDL, array(
                'exceptions' => TRUE,
                'location' => $webURL,
                'trace' => TRUE,));

    $this->sessionid = $this->client->login($this->companyid, $this->username, $this->password);
  }

  protected static function checkForFullRelavency($item) {
    return $item->relevance == (float) 1;
  }

  public function findCandidateIDByEmail($email) {
    try {
      $ret = $this->client->searchCandidate($this->sessionid, array('email' => $email));
    }
    catch (Exception $e) {
      $this->saveLastRequest();
      throw $e;
    }

    $this->saveLastRequest();
    if (!property_exists($ret->array, 'item')) {
      return false;
    }
    else if (!is_array($ret->array->item)) {
      $ret->array->item = array($ret->array->item);
    }

    return $ret->array->item[0]->id;

  }

  public function findRequisitionsForPublishing($property = 'Post To Website', $require_already_approved = FALSE) {

    try {
        if ($require_already_approved) {
          $ret = $this->client->searchRequisition($this->sessionid, array($property => 'true', 'status' => 'Open', 'IsApprovedForWebsite' => 'true'));
        }
        else {
          $ret = $this->client->searchRequisition($this->sessionid, array($property => 'true', 'status' => 'Open'));
        }
    }
    catch (Exception $e) {
      $this->saveLastRequest();
      throw $e;
    }

    $this->saveLastRequest();
    if (!property_exists($ret->array, 'item')) {
      $ret->array->item = array();
    }
    else if (!is_array($ret->array->item)) {
      $ret->array->item = array($ret->array->item);
    }
    $ret = array_filter($ret->array->item, array(__CLASS__, 'checkForFullRelavency'));
    return $ret;
  }

  protected function saveLastRequest() {

    $this->raw = array(
        'requestHeaders' => $this->client->__getLastRequestHeaders(),
        'request' => $this->client->__getLastRequest(),
        'responseHeaders' => $this->client->__getLastResponseHeaders(),
        'response' => $this->client->__getLastResponse(),
    );
  }

  public function getLastRequest() {
    return $this->raw;
  }

  public function getRequisitionById($id) {

    try {
      $ret = $this->client->getRequisitionById($this->sessionid, $id);
    }
    catch (Exception $e) {
      $this->saveLastRequest();
      throw $e;
    }

    $this->saveLastRequest();
    return $ret;
  }
  
  public function updateRequisition($requisition) {
    try {
      $ret = $this->client->updateRequisition($this->sessionid, $requisition);
    }
    catch (Exception $e) {
      $this->saveLastRequest();
      throw $e;
    }

    $this->saveLastRequest();
    return $ret;
  }
  
  public function getCandidateById($id) {
    try {
      $ret = $this->client->getCandidateById($this->sessionid, $id);
    }
    catch (Exception $e) {
      $this->saveLastRequest();
      throw $e;
    }
    
    $this->saveLastRequest();
    return $ret;
  }

}
?>
