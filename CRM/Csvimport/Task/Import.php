<?php

class CRM_Csvimport_Task_Import {

  /**
   * Callback function for entity import task
   *
   * @param CRM_Queue_TaskContext $ctx
   * @param $entity
   * @param $batch
   * @return bool
   */
  public static function ImportEntity(CRM_Queue_TaskContext $ctx, $entity, $batch) {

    if( !$entity || !isset($batch)) {
      CRM_Core_Session::setStatus('Invalid params supplied to import queue!', 'Queue task - Init', 'error');
      return false;
    }

    // process items from batch
    foreach ($batch as $params) {
      // add validation for options select fields
      $validation = self::validateFields($entity, $params);
      foreach ($validation as $fieldName => $valInfo) {
        if ($valInfo['error']) {
          // remove this row from active fields
          CRM_Core_Session::setStatus($valInfo['error'], 'Queue task - Validation', 'error');
          return false;
        }
        if (isset($valInfo['valueUpdated'])) {
          // if 'label' is used instead of 'name' or if multivalued fields using '|'
          $params[$valInfo['valueUpdated']['field']] = $valInfo['valueUpdated']['value'];
        }
      }

      // check for api chaining in params and run them separately
      foreach ($params as $k => $param) {
        if (is_array($param) && count($param) == 1) {
          reset($param);
          $key = key($param);
          if (strpos($key, 'api.') === 0 && strpos($key, '.get') === (strlen($key) - 4)) {
            $refEntity = substr($key, 4, strlen($key) - 8);

            // special case: handle 'Master Address Belongs To' field using contact external_id
            if ($refEntity == 'Address' && isset($param[$key]['external_identifier'])) {
              try {
                $res = civicrm_api3('Contact', 'get', $param[$key]);
              } catch (CiviCRM_API3_Exception $e) {
                $error = $e->getMessage();
                array_unshift($values, $error);
                CRM_Core_Session::setStatus('Error handling \'Master Address Belongs To\'! (' . $error . ')', 'Queue task - Import', 'error');
                return false;
              }
              $param[$key]['contact_id'] = $res['values'][0]['id'];
              unset($param[$key]['external_identifier']);
            }

            try {
              $data = civicrm_api3($refEntity, 'get', $param[$key]);
            } catch (CiviCRM_API3_Exception $e) {
              $error = $e->getMessage();
              array_unshift($values, $error);
              CRM_Core_Session::setStatus('Error with referenced entity "get"! (' . $error . ')', 'Queue task - Import', 'error');
              return false;
            }
            $params[$k] = $data['values'][0]['id'];
          }
        }
      }

      try {
        civicrm_api3($entity, 'create', $params);
      } catch (CiviCRM_API3_Exception $e) {
        $error = $e->getMessage();
        array_unshift($values, $error);
        CRM_Core_Session::setStatus('Error with entity "create"! (' . $error . ')', 'Queue task - Import', 'error');
        return false;
      }
    }

    return true;
  }

  /**
   * Validates field-value pairs before importing
   *
   * @param $params
   * @return array
   * @throws CiviCRM_API3_Exception
   */
  private static function validateFields($entity, $params) {
    $opFields = civicrm_api3($entity, 'getfields', array(
      'api_action' => "getoptions",
      'options' => array('get_options' => "all", 'get_options_context' => "match", 'params' => array()),
      'params' => array(),
    ))['values']['field']['options'];
    $opFields = array_keys($opFields);
    $valInfo = array();
    foreach ($params as $fieldName => $value) {
      if(in_array($fieldName, $opFields)) {
        $valInfo[$fieldName] = self::validateField($entity, $fieldName, $value);
      }
    }

    return $valInfo;
  }

  /**
   * Validates given option/value field against allowed values
   * Also handles multi valued fields separated by '|'
   *
   * @param $field
   * @param $value
   * @return array
   * @throws CiviCRM_API3_Exception
   */
  private static function validateField($entity, $field, $value) {
    $options = civicrm_api3($entity, 'getoptions', array(
      'field' => $field,
      'context' => "match",
    ))['values'];
    $value = explode('|', $value);
    $optionKeys = array_keys($options);
    $valueUpdated = FALSE;
    $isValid = TRUE;

    foreach ($value as $k => $mval) {
      if(!empty($mval) && !in_array($mval, $optionKeys)) {
        $isValid = FALSE;
        // check 'label' if 'name' not found
        foreach ($options as $name => $label) {
          if($mval == $label) {
            $value[$k] = $name;
            $valueUpdated = TRUE;
            $isValid = TRUE;
          }
        }
        if(!$isValid) {
          return array('error' => ts('Invalid value for field') . ' (' . $field . ') => ' . $mval);
        }
      }
    }

    if(count($value) == 1) {
      if(!$valueUpdated) {
        return array('error' => 0);
      }
      $value = array_pop($value);
    }

    return array('error' => 0, 'valueUpdated' => array('field' => $field, 'value' => $value));
  }
}
