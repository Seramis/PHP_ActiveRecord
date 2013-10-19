<?php

namespace AR;

abstract class ActiveRecord
{
	protected static $aDefinition = array(
		'sTable' => null,
		'sIdField' => null,
		'aField' => array()
	);

	/** @var array[] */
	private static $aCache = array();
	/** @var \PDO */
	private static $oPdo = null;

	private $bLoaded = false;
	private $aData = array();
	private $aNewData = array();

	/**
	 * @param \PDO $oPdo
	 */
	public static function setPdo(\PDO $oPdo)
	{
		self::$oPdo = $oPdo;
	}

	/**
	 * @param string $sId
	 *
	 * @return ActiveRecord|bool False on no result
	 */
	public static function getById($sId)
	{
		$sObject = self::getClassName();

		return self::getObject(
			$sObject,
			array(self::getDef('sIdField') => $sId)
		);
	}

	/**
	 * @param array $aConditionBinds array(array('condition', $value, $value), array('condition', $value, $value))
	 *
	 * @return ActiveRecord|bool False on no result
	 */
	public static function getOne($aConditionBinds)
	{
		$aResult = self::getMany($aConditionBinds);

		return reset($aResult);
	}

	/**
	 * @param array $aConditionBinds Key: field name, Value: field value
	 *
	 * @return array|ActiveRecord[] Empty result is empty array
	 */
	public static function getMany($aConditionBinds)
	{
		$sSql = 'SELECT %self.table%.*
			FROM %self.table%
			WHERE `' . join('` = ? AND `', array_keys($aConditionBinds)) . '` = ?';

		return self::getManyBySql($sSql, array_values($aConditionBinds));
	}

	/**
	 * Performs SQL to provide ActiveRecord instances.
	 * Can replace keywords:
	 *    %Model.table% - ActiveRecord's table name
	 *    %Model.id% - ActiveRecord's id field name
	 * Model should be replaced with model name or with 'self'
	 *
	 * @param string $sSql
	 * @param null|array $aParams
	 *
	 * @return array|ActiveRecord[] Empty result is empty array
	 */
	public static function getManyBySql($sSql, $aParams = null)
	{
		//Parses sql and gives us map too
		$aMap = self::parseQueryString($sSql);

		$oStmt = self::$oPdo->prepare($sSql);
		$oStmt->execute($aParams);

		$aResultSet = self::pdoFetchAllNested($oStmt);

		$aResult = array();
		foreach($aResultSet as $sTable => $aRowList)
		{
			foreach($aRowList as $aData)
			{
				$oObject = self::getObject($aMap[$sTable], $aData);

				//It's our table and we don't object in resultset yet
				if($sTable == self::getDef('sTable') && !in_array($oObject, $aResult))
				{
					$aResult[] = $oObject;
				}
			}
		}

		return $aResult;
	}

	/**
	 * Return definition(key)
	 *
	 * @param string $sKey
	 *
	 * @return array|string
	 */
	protected static function getDef($sKey = null)
	{
		if($sKey === null)
		{
			return static::$aDefinition;
		}

		return static::$aDefinition[$sKey];
	}

	/**
	 * Get object from cache. If needed, create one. If possible, prefill with data.
	 *
	 * @param string $sObject Class name
	 * @param array $aDataArray Array of data. ID field is mandatory, others are optional.
	 *
	 * @return ActiveRecord
	 */
	private static function getObject($sObject, $aDataArray)
	{
		$sId = $aDataArray[$sObject::getDef('sIdField')];

		if(!$sId)
		{
			trigger_error('No id provided with data array!', E_USER_ERROR);
		}

		if(!isset(self::$aCache[$sObject][$sId]))
		{
			self::$aCache[$sObject][$sId] = new $sObject();
			self::$aCache[$sObject][$sId]->init($sId);
		}

		/** @var ActiveRecord $oObject */
		$oObject = self::$aCache[$sObject][$sId];

		//If object is not loaded and we have all fields, we can preload object
		if(!$oObject->bLoaded && count(array_diff($sObject::getDef('aField'), array_keys($aDataArray))) == 0)
		{
			$oObject->loadFromArray($aDataArray);
		}

		return $oObject;
	}

	/**
	 * Query parser that parses argument and returns map of additional ActiveRecords found.
	 *
	 * @param string $sSql This parameter will be changed
	 *
	 * @return array Mapping; Class_Name => table_name
	 */
	private static function parseQueryString(&$sSql)
	{
		$aMap = array();

		//Match all placeholders like: %blah.blah%
		while(preg_match('#\%([A-Za-z_\\\\]*?)\.([A-Za-z]*?)\%#', $sSql, $aMatches))
		{
			$sObject = self::getClassName($aMatches[1]);

			if(!class_exists($sObject))
			{
				trigger_error('Unable to find model "' . $sObject . '" while parsing query! Query: ' . $sSql, E_USER_ERROR);
			}

			//We don't need to add ourselves to map and also we dont need to put same thing in map multiple times
			if(!isset($aMap[$sObject::getDef('sTable')]))
			{
				$aMap[$sObject::getDef('sTable')] = $sObject;
			}

			switch($aMatches[2])
			{
				case 'id':
					$sValue = '`' . $sObject::getDef('sIdField') . '`';
					break;
				case 'table':
					$sValue = '`' . $sObject::getDef('sTable') . '`';
					break;
				default:
					trigger_error('Unable to parse placeholder "' . $aMatches[0] . '"! Query: ' . $sSql, E_USER_ERROR);
			}

			$sSql = str_replace($aMatches[0], $sValue, $sSql);
		}

		return $aMap;
	}

	/**
	 * Returns nested array in a form:
	 * array(
	 *  table_name => array(
	 *     row_counter => array(
	 *      field_name=>field_value
	 *     )
	 *    )
	 * )
	 *
	 * @param \PDOStatement $oStmt
	 *
	 * @return array[]
	 */
	private static function pdoFetchAllNested(\PDOStatement $oStmt)
	{
		//Get data about returned fields
		$aTableField = array();
		for($i = 0; $i < $oStmt->columnCount(); $i++)
		{
			$aMetaData = $oStmt->getColumnMeta($i);
			$aTableField[] = array($aMetaData['table'], $aMetaData['name']);
		}

		//Parse all returned fields into a nice array
		$aResult = array();
		for($iRowIndex = 0; ($aRow = $oStmt->fetch(\PDO::FETCH_NUM)) !== false; $iRowIndex++)
		{
			foreach($aRow as $iKey => $mValue)
			{
				$aFieldData = $aTableField[$iKey];

				//Table, row, field
				$aResult[$aFieldData[0]][$iRowIndex][$aFieldData[1]] = $mValue;
			}
		}

		return $aResult;
	}

	/**
	 * Returns fully qualified name of model with same namespace
	 * Implements keyword 'self'.
	 *
	 * @param null|string $sCalledClass
	 *
	 * @return string
	 */
	private static function getClassName($sCalledClass = null)
	{
		//If no value or 'self' keyword, it's this class
		if($sCalledClass == null || $sCalledClass == 'self')
		{
			$sCalledClass = '\\' . get_called_class();
		}

		//If there is no namespaces mentioned, use this class' namespace (if exists)
		if(!stristr($sCalledClass, '\\'))
		{
			$sSelfClass = get_called_class();
			$sNamespace = substr($sSelfClass, 0, strrpos($sSelfClass, '\\'));
			if($sNamespace)
			{
				$sNamespace = $sNamespace . '\\';
			}
			$sCalledClass = '\\' . $sNamespace . $sCalledClass;
		}

		//If path is relative (no \ in beginning), append this class' namespace to the beginning (if exists)
		if($sCalledClass{0} != '\\')
		{
			$sSelfClass = get_called_class();
			$sNamespace = substr($sSelfClass, 0, strrpos($sSelfClass, '\\'));
			if($sNamespace)
			{
				$sNamespace = $sNamespace . '\\';
			}
			$sCalledClass = '\\' . $sNamespace . $sCalledClass;
		}

		return $sCalledClass;
	}

	/**
	 * Create new ActiveRecord.
	 *
	 * @param array $aSetData Set array as new content for AR
	 */
	public function __construct($aSetData = array())
	{
		if(count($aSetData))
		{
			foreach($aSetData as $sName => $mValue)
			{
				$this->$sName = $mValue;
			}
		}

		//It's new AR, there's no ID
		$this->aData[self::getDef('sIdField')] = null;
	}

	/**
	 * @param string $sKey
	 *
	 * @return bool
	 */
	public function __isset($sKey)
	{
		return in_array($sKey, self::getDef('aField'));
	}

	/**
	 * Getter for ActiveRecord properties.
	 *
	 * Will trigger:
	 *    _preGet()
	 *    _preGet_field_name()
	 *    _postGet_field_name()
	 *    _postGet()
	 *
	 * @param string $sKey
	 *
	 * @return mixed
	 */
	public function __get($sKey)
	{
		if(!$this->__isset($sKey))
		{
			trigger_error('Trying to set non-existing property of "' . $sKey . '"!', E_USER_ERROR);
		}

		if($this->getIsInDb() && array_key_exists($sKey, $this->aNewData))
		{
			trigger_error('Trying to get value of property "' . $sKey . '" that is set but not saved!', E_USER_ERROR);
		}

		//_pre events
		if(method_exists($this, '_preGet'))
		{
			if($this->{'_preGet'}($sKey) === false)
			{
				return false;
			}
		}
		if(method_exists($this, '_preGet_' . $sKey))
		{
			if($this->{'_preGet_' . $sKey}() === false)
			{
				return false;
			}
		}

		//Getting the value
		if($sKey == self::getDef('sIdField'))
		{
			$mValue = $this->getId();
		}
		else
		{
			if($this->getIsInDb())
			{
				$this->load();
				$mValue = $this->aData[$sKey];
			}
			else //If not in DB, return new value or NULL, when value is unset
			{
				$mValue = array_key_exists($sKey, $this->aNewData) ? $this->aNewData[$sKey] : null;
			}
		}

		//_post events
		if(method_exists($this, '_postGet_' . $sKey))
		{
			$this->{'_postGet_' . $sKey}($mValue);
		}
		if(method_exists($this, '_postGet'))
		{
			$this->{'_postGet'}($sKey, $mValue);
		}

		return $mValue;
	}

	/**
	 * Sets a value to ActiveRecord.
	 * If new value is same as old value (in DB), it is not registered to be saved to DB.
	 *
	 * Will trigger:
	 *    _preSet()
	 *    _preSet_field_name()
	 *    _postSet_field_name()
	 *    _postSet()
	 *
	 * @param string $sKey
	 * @param mixed $mValue
	 *
	 * @return mixed Value that has been assigned
	 */
	public function __set($sKey, $mValue)
	{
		if($sKey == self::getDef('sIdField'))
		{
			trigger_error('You can not set id!', E_USER_ERROR);
		}

		//_pre events
		if(method_exists($this, '_preSet'))
		{
			if($this->{'_preSet'}($sKey, $mValue) === false)
			{
				return false;
			}
		}
		if(method_exists($this, '_preSet_' . $sKey))
		{
			if($this->{'_preSet_' . $sKey}($mValue) === false)
			{
				return false;
			}
		}

		$this->aNewData[$sKey] = $mValue;

		//If we are loaded and we can see, that new value is same as old one, no need to keep that value.
		if($this->bLoaded && $this->aNewData[$sKey] == $this->aData[$sKey])
		{
			unset($this->aNewData[$sKey]);
		}

		//_post events
		if(method_exists($this, '_postSet_' . $sKey))
		{
			$this->{'_postSet_' . $sKey}($mValue);
		}
		if(method_exists($this, '_postSet'))
		{
			$this->{'_postSet'}($sKey, $mValue);
		}

		return $mValue;
	}

	/**
	 * @return string ID value of ActiveRecord
	 */
	public function getId()
	{
		return $this->aData[self::getDef('sIdField')];
	}

	/**
	 * @return bool
	 */
	public function getIsInDb()
	{
		return $this->getId() !== null;
	}

	/**
	 * Sets object in not loaded state. When something is accessed, lazy-load will load object's content.
	 */
	public function setNotLoaded()
	{
		$this->bLoaded = false;
	}

	/**
	 * All new data, what is not saved, is truncated from object
	 */
	public function truncateNewData()
	{
		$this->aNewData = array();
	}

	/**
	 * Saves or creates row in DB.
	 *
	 * Save will trigger events:
	 *    _preSave()
	 *    _preSave_field_name()
	 *    _postSave_field_name()
	 *    _postSave()
	 *
	 * @return bool Was saving successful
	 */
	public function save()
	{
		//_pre events
		if(method_exists($this, '_preSave'))
		{
			if($this->{'_preSave'}($this->aNewData) === false)
			{
				return false;
			}
		}
		foreach($this->aNewData as $sKey => &$mValue)
		{
			if(method_exists($this, '_preSave_' . $sKey) && $this->{'_preSave_' . $sKey}($mValue) === false)
			{
				return false;
			}
		}

		if($this->getId() === null)
		{
			$sSql = 'INSERT INTO `' . self::getDef('sTable') . '`
			 	(`' . join('`,`', array_keys($this->aNewData)) . '`)
				VALUES
				(' . trim(str_repeat('?,', count($this->aNewData)), ',') . ');';

			self::$oPdo->prepare($sSql)
				->execute(array_values($this->aNewData));

			$this->aData[self::getDef('sIdField')] = self::$oPdo->lastInsertId();

			//Insert object into cache
			$sObject = self::getClassName();

			self::$aCache[$sObject][$this->getId()] = $this;
		}
		else
		{
			$sSql = 'UPDATE `' . self::getDef('sTable') . '` SET ';
			foreach(array_keys($this->aNewData) as $sField)
			{
				$sSql .= '`' . $sField . '` = ?, ';
			}
			$sSql = trim($sSql, ' ,');
			$sSql .= ' WHERE `' . self::getDef('sIdField') . '` = ?;';

			$aParams = array_values($this->aNewData);
			$aParams[] = $this->getId();

			self::$oPdo->prepare($sSql)
				->execute($aParams);
		}

		$aNewData = $this->aNewData;
		$this->aNewData = array();
		$this->setNotLoaded();

		//_post events
		if(method_exists($this, '_postSave'))
		{
			$this->{'_postSave'}($aNewData);
		}
		foreach($aNewData as $sKey => $mValue)
		{
			if(method_exists($this, '_postSave_' . $sKey))
			{
				$this->{'_postSave_' . $sKey}($mValue);
			}
		}

		return true;
	}

	/**
	 * Deletes object from DB.
	 * Object will stay, in php, you can not destroy yourself.
	 *
	 * Will trigger events:
	 *    _preDelete()
	 *    _postDelete()
	 *
	 * @return bool
	 */
	public function delete()
	{
		if(!$this->getIsInDb())
		{
			trigger_error('Trying to delete object that is not in DB!', E_USER_ERROR);
		}

		if(method_exists($this, '_preDelete') && $this->{'_preDelete'}() === false)
		{
			return false;
		}

		self::$oPdo->prepare('DELETE FROM `' . self::getDef('sTable') . '` WHERE `' . self::getDef('sIdField') . '` = ?;')
			->execute(array($this->getId()));

		//Delete object from cache
		$sObject = self::getClassName();
		unset(self::$aCache[$sObject][$this->getId()]);

		if(method_exists($this, '_postDelete'))
		{
			$this->{'_postDelete'}();
		}

		$this->setNotLoaded();

		$this->aData[self::getDef('sIdField')] = null; //Reset ID, we are now not in DB

		return true;
	}

	/**
	 * Private method to initiate existing rows as ActiveRecords
	 *
	 * @param mixed $sId
	 */
	private function init($sId = null)
	{
		$this->aData[self::getDef('sIdField')] = $sId;
	}

	/**
	 * Loads ActiveRecord's content from DB.
	 *
	 * @return bool
	 */
	private function load()
	{
		if($this->bLoaded)
		{
			return false;
		}

		$sSql = 'SELECT *
			FROM `' . self::getDef('sTable') . '`
			WHERE `' . self::getDef('sIdField') . '` = ?
			LIMIT 1;';

		$oStmt = self::$oPdo->prepare($sSql);
		$oStmt->execute(array($this->getId()));

		$aData = self::pdoFetchAllNested($oStmt);

		return $this->loadFromArray($aData[self::getDef('sTable')][0]);
	}

	/**
	 * Fills ActiveRecord with data and sets ActiveRecord as loaded.
	 *
	 * @param array $aData
	 *
	 * @return bool
	 */
	private function loadFromArray($aData)
	{
		$this->setNotLoaded();

		$aFieldsMandatory = self::getDef('aField');
		unset($aFieldsMandatory[array_search(self::getDef('sIdField'), $aFieldsMandatory)]);

		$aMissingFields = array_diff($aFieldsMandatory, array_keys($aData));

		if(count($aMissingFields))
		{
			trigger_error('Loading array is missing fields: ' . join(', ', $aMissingFields) . '!', E_USER_ERROR);
		}

		$this->aData = array_merge(
			$this->aData,
			array_intersect_key($aData, array_flip($aFieldsMandatory))
		);

		$this->bLoaded = true;

		return true;
	}
}
