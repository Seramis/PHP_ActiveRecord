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
	public static $aCache = array();
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

		self::$oPdo->setAttribute(\PDO::ATTR_FETCH_TABLE_NAMES, true);
	}

	/**
	 * @param string $sId
	 *
	 * @return ActiveRecord|false
	 */
	public static function getById($sId)
	{
		return self::getOne(array(self::getDef('sIdField') => $sId));
	}

	/**
	 * @param array $aConditionBinds Key: field name, Value: field value
	 *
	 * @return ActiveRecord|false
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
		$sSql = 'SELECT `' . join('`,`', self::getDef('aField')) . '`
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
		$sObject = get_called_class();

		//Parses sql and gives us map too
		$aMap = self::parseQueryString($sSql);

		$oStmt = self::$oPdo->prepare($sSql);
		$oStmt->execute($aParams);

		$aResultSet = self::pdoFetchAllNested($oStmt);

		$aResult = array();
		foreach($aResultSet as $aRow)
		{
			foreach($aRow as $sTable => $aData)
			{
				if($sTable == self::getDef('sTable')) //It's our table
				{
					$oObject = self::getObject($sObject, $aData);
					if(!in_array($oObject, $aResult))
					{
						$aResult[] = self::getObject($sObject, $aData);
					}
				}
				else
				{
					//If we have mapping for that table, cache that object
					$sSideObject = array_search($sTable, $aMap);
					if($sSideObject)
					{
						self::getObject($sSideObject, $aData);
					}
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

        if(!array_key_exists($sObject, self::$aCache))
        {
            self::$aCache[$sObject] = array();
        }

        if(!array_key_exists($sId, self::$aCache[$sObject]))
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
		$sSelf = get_called_class();
		$aMap = array();

		//Match all placeholders like: %blah.blah%
		while(preg_match('#\%([A-Za-z_]*?)\.([A-Za-z]*?)\%#', $sSql, $aMatches))
		{
			$sObject = $aMatches[1];
			if($sObject == 'self')
			{
				$sObject = $sSelf;
			}

			if(!class_exists($sObject))
			{
				trigger_error('Unable to find model "' . $sObject . '" while parsing query! Query: ' . $sSql, E_USER_ERROR);
			}

			//We don't need to add ourselves to map and also we dont need to put same thing in map multiple times
			if($sObject != $sSelf && !array_key_exists($sObject, $aMap))
			{
				$aMap[$sObject] = $sObject::getDef('sTable');
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
	 *  row_counter => array(
	 * 	 table_name => array(
	 * 	  field_name=>field_value
	 * 	 )
	 * 	)
	 * )
	 *
	 * @param \PDOStatement $oStmt
	 *
	 * @return array[]
	 */
	private static function pdoFetchAllNested(\PDOStatement $oStmt)
	{
		$aResult = array();

		while(($aRowData = $oStmt->fetch(\PDO::FETCH_ASSOC)) !== false)
		{
			$aRow = array();

			foreach($aRowData as $sKey => $mValue)
			{
				list($sTable, $sKey) = explode('.', $sKey);

				$aRow[$sTable][$sKey] = $mValue;
			}

			$aResult[] = $aRow;
		}

		return $aResult;
	}

	/**
	 * Create new ActiveRecord.
	 */
	public function __construct()
	{
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
	 * 	_preGet()
	 * 	_preGet_field_name()
	 * 	_postGet_field_name()
	 * 	_postGet()
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

		//If the key isn't id field, load object
		if($this->getIsInDb() && $sKey != self::getDef('sIdField'))
		{
			$this->load();
		}

		$mValue = $this->getIsInDb() ? $this->aData[$sKey] : $this->aNewData[$sKey];

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
	 * 	_preSet()
	 * 	_preSet_field_name()
	 * 	_postSet_field_name()
	 * 	_postSet()
	 *
	 * @param string $sKey
	 * @param mixed $mValue
	 *
	 * @return mixed Value that has been assigned
	 */
	public function __set($sKey, $mValue)
	{
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
	 * Saves or creates row in DB.
	 *
	 * Save will trigger events:
	 * 	_preSave()
	 * 	_preSave_field_name()
	 * 	_postSave_field_name()
	 * 	_postSave()
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
			$sObject = get_called_class();

			if(!array_key_exists($sObject, self::$aCache))
			{
				self::$aCache[$sObject] = array();
			}

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
	 * 	_preDelete()
	 * 	_postDelete()
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
		$sObject = get_called_class();
		unset(self::$aCache[$sObject][$this->getId()]);

		if(method_exists($this, '_postDelete'))
		{
			$this->{'_postDelete'}();
		}

		$this->setNotLoaded();

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

		$sSql = 'SELECT `' . join('`,`', self::getDef('aField')) . '`
			FROM `' . self::getDef('sTable') . '`
			WHERE `' . self::getDef('sIdField') . '` = ?
			LIMIT 1;';

		$oStmt = self::$oPdo->prepare($sSql);
		$oStmt->execute(array($this->getId()));

		$aData = self::pdoFetchAllNested($oStmt);

		return $this->loadFromArray($aData[0][self::getDef('sTable')]);
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

		$aMissingFields = array_diff(self::getDef('aField'), array_keys($aData));

		if(count($aMissingFields))
		{
			trigger_error('Loading array is missing fields: ' . join(', ', $aMissingFields) . '!', E_USER_ERROR);
		}

		$this->aData = array_intersect_key($aData, array_flip(self::getDef('aField')));

		$this->bLoaded = true;

		return true;
	}
}