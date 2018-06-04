<?php
class CRM_Csvimport_Import_Parser_Api extends CRM_Csvimport_Import_Parser_BaseClass {
  protected $_entity = '';// for now - set in form
  protected $_fields = array();
  protected $_requiredFields = array();
  protected $_dateFields = array();
  /**
   * Params for the current entity being prepared for the api
   * @var array
   */
  protected $_params = array();
  protected $_refFields = array();
  protected $_importQueueBatch = array();
  protected $_allowEntityUpdate = FALSE;
  protected $_ignoreCase = FALSE;

  function setFields() {
   $fields = civicrm_api3($this->_entity, 'getfields', array('action' => 'create'));
   $this->_fields = $fields['values'];
   foreach ($this->_fields as $field => $values) {
     if(!empty($values['api.required'])) {
       $this->_requiredFields[] = $field;
     }
     if(empty($values['title']) && !empty($values['label'])) {
       $this->_fields[$field]['title'] = $values['label'];
     }
     // date is 4 & time is 8. Together they make 12 - in theory a binary operator makes sense here but as it's not a common pattern it doesn't seem worth the confusion
     if(CRM_Utils_Array::value('type', $values) == 12
     || CRM_Utils_Array::value('type', $values) == 4) {
       $this->_dateFields[] = $field;
     }
   }
   foreach ($this->_refFields as $field => $values) {
     if(isset($this->_fields[$values->id])) {
       $this->_fields[$field] = $this->_fields[$values->id];
       $this->_fields[$values->id]['_refField'] = $values->entity_field_name;
     }
   }
   $this->_fields = array_merge(array('do_not_import' => array('title' => ts('- do not import -'))), $this->_fields);
  }

  /**
   * The summary function is a magic & mystical function I have only partially made sense of but note that
   * it makes a call to setActiveFieldValues - without which import won't work - so it's more than just a presentation
   * function
   * @param array $values the array of values belonging to this line
   *
   * @return boolean      the result of this processing
   * It is called from both the preview & the import actions
   * (non-PHP doc)
   * @see CRM_Csvimport_Import_Parser_BaseClass::summary()
   */
  function summary(&$values) {
   $erroneousField = NULL;
   $response      = $this->setActiveFieldValues($values, $erroneousField);
   $errorRequired = FALSE;
   $missingField = '';
   $this->_params = &$this->getActiveFieldParams();

   foreach ($this->_requiredFields as $requiredField) {
     if(empty($this->_params['id']) && empty($this->_params[$requiredField])) {
       $errorRequired = TRUE;
       $missingField .= ' ' . $requiredField;
       CRM_Contact_Import_Parser_Contact::addToErrorMsg($this->_entity, $requiredField);
     }
   }

   if ($errorRequired) {
    array_unshift($values, ts('Missing required field(s) :') . $missingField);
    return CRM_Import_Parser::ERROR;
   }

   $errorMessage = NULL;
   //@todo add a validate fn to the apis so that we can dry run against them to check
   // pseudoconstants
   if ($errorMessage) {
     $tempMsg = "Invalid value for field(s) : $errorMessage";
     array_unshift($values, $tempMsg);
     $errorMessage = NULL;
     return CRM_Import_Parser::ERROR;
   }
   return CRM_Import_Parser::VALID;
  }

  /**
   * handle the values in import mode
   *
   * @param int $onDuplicate the code for what action to take on duplicates
   * @param array $values the array of values belonging to this line
   *
   * @return boolean      the result of this processing
   * @access public
   */
  function import($onDuplicate, &$values) {
    $response = $this->summary($values);
    $this->_params = $this->getActiveFieldParams(true);
    $this->formatDateParams();
    $this->_params['skipRecentView'] = TRUE;
    $this->_params['check_permissions'] = TRUE;

    if(count($this->_importQueueBatch) >= $this->getImportQueueBatchSize()) {
      $this->addBatchToQueue();
    }
    $this->addToBatch($this->_params, $values);

  }

  /**
   * Format Date params
   *
   * Although the api will accept any strtotime valid string CiviCRM accepts at least one date format
   * not supported by strtotime so we should run this through a conversion
   * @internal param \unknown $params
   */
  function formatDateParams() {
    $session = CRM_Core_Session::singleton();
    $dateType = $session->get('dateTypes');
    $setDateFields = array_intersect_key($this->_params, array_flip($this->_dateFields));
    foreach ($setDateFields as $key => $value) {
      CRM_Utils_Date::convertToDefaultDate($this->_params, $dateType, $key);
      $this->_params[$key] = CRM_Utils_Date::processDate($this->_params[$key]);
    }
  }

  /**
   * Set import entity
   * @param string $entity
   */
  function setEntity($entity) {
    $this->_entity = $entity;
  }

  /**
   * Set reference fields; array of ReferenceField objects
   * @param string $entity
   */
  function setRefFields($val) {
    $this->_refFields = $val;
  }

  /**
   * Set batch size for import queue
   * @param $size
   */
  function setImportQueueBatchSize($size) {
    $this->_importQueueBatchSize = $size;
  }

  /**
   * Get batch size for import queue
   * @return int
   */
  function getImportQueueBatchSize() {
    if($this->_importQueueBatchSize) {
      return $this->_importQueueBatchSize;
    }
    return 1;
  }

  /**
   * Add an item to current import batch
   * @param $item
   */
  function addToBatch($item, $values) {
    $item['rowValues'] = $values;
    $item['allowUpdate'] = $this->_allowEntityUpdate;
    $item['ignoreCase'] = $this->_ignoreCase;
    $this->_importQueueBatch[] = $item;
  }

  /**
   * Add all items in current batch to queue
   */
  function addBatchToQueue() {
    if(count($this->_importQueueBatch) == 0) {
      return;
    }
    $queueParams = array(
      'entity' => $this->_entity,
      'params' => $this->_importQueueBatch,
      'errorFileName' => $this->_errorFileName,
    );
    $task = new CRM_Queue_Task(
      array('CRM_Csvimport_Task_Import', 'ImportEntity'),
      $queueParams,
      ts('Importing entity') . ': ' . $this->_lineCount
    );
    $this->_importQueue->createItem($task);
    $this->_importQueueBatch = array();
  }

  /**
   * Set if entities can be updated using unique fields
   * @param $size
   */
  function setAllowEntityUpdate($update) {
    $this->_allowEntityUpdate = $update;
  }

  /**
   * Set if letter-case needs to be ignored for field option values
   * @param $size
   */
  function setIgnoreCase($ignoreCase) {
    $this->_ignoreCase = $ignoreCase;
  }

}
