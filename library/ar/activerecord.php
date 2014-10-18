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
	 * @param bool $bOnlyFromCache If true, will return AR only, if it's already in cache
	 *
	 * @return ActiveRecord|bool False on no result
	 */
	public static function getById($sId, $bOnlyFromCache = false)
	{
		if($sId == null)
		{
			return false;
		}

		$sObject = self::getClassName();

		if(isset(self::$aCache[$sObject][$sId]))
		{
			return self::$aCache[$sObject][$sId];
		}

		if($bOnlyFromCache)
		{
			return false;
		}

		return static::getOne(array(
			static::getDef('sIdField') . ' = ?' => $sId
		));
	}

	/**
	 * @param array $aCondition array('a = 1' => null, 'b = ?' => $value, 'c BETWEEN ? AND ?' => array($value, $value))
	 * @param null|string $sSuffix Example: ORDER BY id DESC
	 *
	 * @return ActiveRecord|bool False on no result
	 */
	public static function getOne($aCondition = array(), $sSuffix = null)
	{
		$aResult = static::getMany($aCondition, $sSuffix);

		return reset($aResult);
	}

	/**
	 * @param array $aCondition array('a = 1' => null, 'b = ?' => $value, 'c BETWEEN ? AND ?' => array($value, $value))
	 * @param null|string $sSuffix Example: ORDER BY id DESC
	 *
	 * @return array|ActiveRecord[] Empty result is empty array
	 */
	public static function getMany($aCondition = array(), $sSuffix = null)
	{
		if(!count($aCondition))
		{
			return static::getManyByWhere(null, null, $sSuffix);
		}

		$sWhere = join(' AND ', array_keys($aCondition));

		$aParams = array();

		foreach(array_values($aCondition) as $aConditionParams)
		{
			if($aConditionParams === null)
			{
				continue;
			}

			if(!is_array($aConditionParams))
			{
				$aConditionParams = array($aConditionParams);
			}

			$aParams = array_merge($aParams, $aConditionParams);
		}

		return static::getManyByWhere($sWhere, $aParams, $sSuffix);
	}

	/**
	 * @param string $sWhere
	 * @param null|array $aParams
	 * @param null|string $sSuffix Example: ORDER BY id DESC
	 *
	 * @return ActiveRecord[]|array Empty result is empty array
	 */
	public static function getManyByWhere($sWhere = null, $aParams = null, $sSuffix = null)
	{
		$sSql = 'SELECT %self.table%.*
			FROM %self.table%';

		if($sWhere)
		{
			$sSql .= ' WHERE ' . $sWhere;
		}

		if($sSuffix)
		{
			$sSql .= ' ' . $sSuffix;
		}

		return static::getManyBySql($sSql, $aParams);
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
				if($sTable == static::getDef('sTable') && !in_array($oObject, $aResult))
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
	 * @param string|null $sKey Definition key. If left empty (null), everything is returned
	 *
	 * @return array|string
	 */
	public static function getDef($sKey = null)
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

			//We don't need to put same thing in map multiple times
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
			return '\\' . get_called_class();
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
			return '\\' . $sNamespace . $sCalledClass;
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
			return '\\' . $sNamespace . $sCalledClass;
		}

		return $sCalledClass;
	}

	/**
	 * Create new ActiveRecord.
	 *
	 * @param array|ActiveRecord $aData Array or object from where data will be read in.
	 */
	public function __construct($mData = array())
	{
		if(count($mData))
		{
			$this->setMany($mData);
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
		return in_array($sKey, static::getDef('aField'));
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

		if($this->isInDb() && array_key_exists($sKey, $this->aNewData))
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
		if($sKey == static::getDef('sIdField'))
		{
			$mValue = $this->getId();
		}
		else
		{
			if($this->isInDb())
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
		if($sKey == static::getDef('sIdField') && $this->isInDb())
		{
			trigger_error('You can not set id of existing row in DB!', E_USER_ERROR);
		}

		$mOldValue = null;
		if($this->bLoaded)
		{
			$mOldValue = $this->aData[$sKey];
		}

		//_pre events
		if(method_exists($this, '_preSet'))
		{
			if($this->{'_preSet'}($sKey, $mValue, $mOldValue) === false)
			{
				return false;
			}
		}
		if(method_exists($this, '_preSet_' . $sKey))
		{
			if($this->{'_preSet_' . $sKey}($mValue, $mOldValue) === false)
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
			$this->{'_postSet_' . $sKey}($mValue, $mOldValue);
		}
		if(method_exists($this, '_postSet'))
		{
			$this->{'_postSet'}($sKey, $mValue, $mOldValue);
		}

		return $mValue;
	}

	/**
	 * @return string ID value of ActiveRecord
	 */
	public function getId()
	{
		return $this->aData[static::getDef('sIdField')];
	}

	/**
	 * @return bool
	 */
	public function isInDb()
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
	 * If given field is set but not saved, returns true.
	 * If no field is given, returns true if any field is set but not saved.
	 *
	 * @param string|null $sFieldName
	 * @return bool
	 */
	public function isDirty($sFieldName = null)
	{
		if($sFieldName !== null)
		{
			return array_key_exists($sFieldName, $this->aNewData);
		}

		return count($this->aNewData) > 0;
	}

	/**
	 * Sets data from array or another object. Skips all fields not present in input and AR's ID field.
	 *
	 * @param array|ActiveRecord $mData
	 * @return boolean
	 */
	public function setMany($mData)
	{
		foreach(static::getDef('aField') as $sField)
		{
			if($sField == static::getDef('sIdField'))
			{
				continue;
			}

			if(is_object($mData) && isset($mData->$sField))
			{
				$this->$sField = $mData->$sField;
				continue;
			}

			if(is_array($mData) && isset($mData[$sField]))
			{
				$this->$sField = $mData[$sField];
				continue;
			}
		}

		return true;
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
	 *    [_preCreate()]
	 *    [_preCreate_field_name()]
	 *    _preSave()
	 *    _preSave_field_name()
	 *    _postSave_field_name()
	 *    _postSave()
	 *    [_postCreate_field_name()]
	 *    [_postCreate()]
	 *
	 * @return bool|null Was saving successful, NULL if nothing was saved
	 */
	public function save()
	{
		//If we don't have anything to save, return null
		if(!$this->isDirty())
		{
			return null;
		}

		$bIsInDb = $this->isInDb();

		//_preCreate triggers (only triggered, if insert will be done)
		if(!$bIsInDb)
		{
			if(method_exists($this, '_preCreate'))
			{
				if($this->{'_preCreate'}($this->aNewData) === false)
				{
					return false;
				}
			}

			foreach($this->aNewData as $sKey => &$mValue)
			{
				if(method_exists($this, '_preCreate_' . $sKey) && $this->{'_preCreate_' . $sKey}($mValue, $this->aNewData) === false)
				{
					return false;
				}
			}
			unset($mValue);
		}

		//_preSave triggers (always triggered)
		if(method_exists($this, '_preSave'))
		{
			if($this->{'_preSave'}($this->aNewData, $this->aData) === false)
			{
				return false;
			}
		}
		foreach($this->aNewData as $sKey => &$mValue)
		{
			$mOldValue = null;
			if(array_key_exists($sKey, $this->aData))
			{
				$mOldValue = $this->aData[$sKey];
			}

			if(method_exists($this, '_preSave_' . $sKey) && $this->{'_preSave_' . $sKey}($mValue, $mOldValue, $this->aNewData, $this->aData) === false)
			{
				return false;
			}
		}
		unset($mValue);

		if(!$bIsInDb)
		{
			$sSql = 'INSERT INTO `' . static::getDef('sTable') . '`
			 	(`' . join('`,`', array_keys($this->aNewData)) . '`)
				VALUES
				(' . trim(str_repeat('?,', count($this->aNewData)), ',') . ');';

			self::$oPdo->prepare($sSql)
				->execute(array_values($this->aNewData));

			$this->aData[static::getDef('sIdField')] = self::$oPdo->lastInsertId();

			//Insert object into cache
			$sObject = self::getClassName();

			self::$aCache[$sObject][$this->getId()] = $this;
		}
		else
		{
			$sSql = 'UPDATE `' . static::getDef('sTable') . '` SET ';
			foreach(array_keys($this->aNewData) as $sField)
			{
				$sSql .= '`' . $sField . '` = ?, ';
			}
			$sSql = trim($sSql, ' ,');
			$sSql .= ' WHERE `' . static::getDef('sIdField') . '` = ?;';

			$aParams = array_values($this->aNewData);
			$aParams[] = $this->getId();

			self::$oPdo->prepare($sSql)
				->execute($aParams);
		}

		$aNewData = $this->aNewData;
		$aOldData = $this->aData;
		$this->aNewData = array();
		$this->setNotLoaded();

		//_postSave triggers
		foreach($aNewData as $sKey => $mValue)
		{
			$mOldValue = null;
			if(array_key_exists($sKey, $aOldData))
			{
				$mOldValue = $aOldData[$sKey];
			}

			if(method_exists($this, '_postSave_' . $sKey))
			{
				$this->{'_postSave_' . $sKey}($mValue, $mOldValue, $aNewData, $aOldData);
			}
		}
		unset($mValue);
		if(method_exists($this, '_postSave'))
		{
			$this->{'_postSave'}($aNewData, $aOldData);
		}

		//_postCreate triggers
		if(!$bIsInDb)
		{
			foreach($aNewData as $sKey => $mValue)
			{
				if(method_exists($this, '_postCreate_' . $sKey))
				{
					$this->{'_postCreate_' . $sKey}($mValue, $aNewData);
				}
			}
			unset($mValue);
			if(method_exists($this, '_postCreate'))
			{
				$this->{'_postCreate'}($aNewData);
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
		if(!$this->isInDb())
		{
			trigger_error('Trying to delete object that is not in DB!', E_USER_ERROR);
		}

		if(method_exists($this, '_preDelete') && $this->{'_preDelete'}() === false)
		{
			return false;
		}

		self::$oPdo->prepare('DELETE FROM `' . static::getDef('sTable') . '` WHERE `' . static::getDef('sIdField') . '` = ?;')
			->execute(array($this->getId()));

		//Delete object from cache
		$sObject = self::getClassName();
		unset(self::$aCache[$sObject][$this->getId()]);

		if(method_exists($this, '_postDelete'))
		{
			$this->{'_postDelete'}();
		}

		$this->setNotLoaded();

		$this->aData[static::getDef('sIdField')] = null; //Reset ID, we are now not in DB

		return true;
	}

	/**
	 * Private method to initiate existing rows as ActiveRecords
	 *
	 * @param mixed $sId
	 */
	private function init($sId = null)
	{
		$this->aData[static::getDef('sIdField')] = $sId;
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
			FROM `' . static::getDef('sTable') . '`
			WHERE `' . static::getDef('sIdField') . '` = ?
			LIMIT 1;';

		$oStmt = self::$oPdo->prepare($sSql);
		$oStmt->execute(array($this->getId()));

		$aData = self::pdoFetchAllNested($oStmt);

		return $this->loadFromArray($aData[static::getDef('sTable')][0]);
	}

	/**
	 * Fills ActiveRecord with data and sets ActiveRecord as loaded.
	 *
	 * Will trigger events:
	 *    _preLoad()
	 *    _preLoad_field_name()
	 *    _postLoad_field_name()
	 *    _postLoad()
	 *
	 * @param array $aData
	 *
	 * @return bool
	 */
	private function loadFromArray($aData)
	{
		$this->setNotLoaded();

		$aFieldsMandatory = static::getDef('aField');
		unset($aFieldsMandatory[array_search(static::getDef('sIdField'), $aFieldsMandatory)]);

		$aMissingFields = array_diff($aFieldsMandatory, array_keys($aData));

		if(count($aMissingFields))
		{
			trigger_error('Loading array is missing fields: ' . join(', ', $aMissingFields) . '!', E_USER_ERROR);
		}

		//_preLoad triggers
		if(method_exists($this, '_preLoad'))
		{
			if($this->{'_preLoad'}($aData, $this->aData) === false)
			{
				return false;
			}
		}
		foreach($aFieldsMandatory as $sField)
		{
			$mOldValue = null;
			if(array_key_exists($sField, $this->aData))
			{
				$mOldValue = $this->aData[$sField];
			}

			if(method_exists($this, '_preLoad_' . $sField) && $this->{'_preLoad_' . $sField}($aData[$sField], $mOldValue, $aData, $this->aData) === false)
			{
				return false;
			}
		}

		$aOldData = $this->aData;
		$this->aData = array_merge(
			$this->aData,
			array_intersect_key($aData, array_flip($aFieldsMandatory))
		);

		//_postLoad triggers
		foreach($aFieldsMandatory as $sField)
		{
			$mOldValue = null;
			if(array_key_exists($sField, $aOldData))
			{
				$mOldValue = $aOldData[$sField];
			}

			if(method_exists($this, '_postLoad_' . $sField))
			{
				$this->{'_postLoad_' . $sField}($this->aData[$sField], $mOldValue, $this->aData, $aOldData);
			}
		}
		if(method_exists($this, '_postLoad'))
		{
			$this->{'_postLoad'}($this->aData, $aOldData);
		}

		$this->bLoaded = true;

		return true;
	}
}
