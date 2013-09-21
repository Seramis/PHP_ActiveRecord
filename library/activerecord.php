<?php

namespace AR;

class ActiveRecord
{
	protected static $aDefinition = array(
		'sTable' => null,
		'sIdField' => null,
		'aField' => array()
	);

	/** @var array|ActiveRecord[] */
	private static $aCache = array();
	/** @var \PDO */
	private static $oPdo = null;

	private $bLoaded = false;
	private $aData = array();
	private $aNewData = array();

	public static function setPdo($oPdo)
	{
		self::$oPdo = $oPdo;
	}

	public static function getById($sId)
	{
		return self::getOne(array(self::getDef('sIdField') => $sId));
	}

	public static function getOne($aConditionBinds)
	{
		$aResult = self::getMany($aConditionBinds);
		return reset($aResult);
	}

	public static function getMany($aConditionBinds)
	{
		$sSql = 'SELECT `' . join('`,`', self::getDef('aField')) . '`
			FROM %table%
			WHERE `' . join('` = ? AND `', array_keys($aConditionBinds)) . '` = ?';

		return self::getManyBySql($sSql, array_values($aConditionBinds));
	}

	public static function getManyBySql($sSql, $aParams = null)
	{
		$sObject = get_called_class();

		//Keywords
		$sSql = str_replace(
			array(
				'%table%',
				'%id%'
			),
			array(
				'`' . self::getDef('sTable') . '`',
				'`' . self::getDef('sIdField') . '`'
			),
			$sSql
		);

		$oStmt = self::$oPdo->prepare($sSql);
		$oStmt->execute($aParams);

		$aResult = array();
		while($aData = $oStmt->fetch(\PDO::FETCH_ASSOC))
		{
			//If we have missing fields, we can not prefill object
			if(count(array_diff(self::getDef('aField'), array_keys($aData))) > 0)
			{
				$aResult[] = self::getObject($sObject, $aData[self::getDef('sIdField')]);
			}
			else
			{
				$aResult[] = self::getObject($sObject, $aData[self::getDef('sIdField')], $aData);
			}
		}

		return $aResult;
	}

	protected static function getDef($sKey = null)
	{
		if($sKey === null)
		{
			return static::$aDefinition;
		}

		return static::$aDefinition[$sKey];
	}

	private static function getObject($sObject, $sId, $aData = array())
	{
		if(!array_key_exists($sObject, self::$aCache))
		{
			self::$aCache[$sObject] = array();
		}

		if(!array_key_exists($sId, self::$aCache[$sObject]))
		{
			self::$aCache[$sObject][$sId] = new $sObject($sId);
		}

		$oObject = self::$aCache[$sObject][$sId];

		if(count($aData))
		{
			$oObject->loadFromArray($aData);
		}

		return self::$aCache[$sObject][$sId];
	}

	public function __construct($sId = null, $aData = array())
	{
		$this->aData[self::getDef('sIdField')] = $sId;

		if(count($aData))
		{
			$this->loadFromArray($aData);
		}
	}

	public function __isset($sKey)
	{
		return in_array($sKey, self::getDef('aField'));
	}

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

	public function getId()
	{
		return $this->aData[self::getDef('sIdField')];
	}

	public function getIsInDb()
	{
		return $this->getId() !== null;
	}

	public function setNotLoaded()
	{
		$this->bLoaded = false;
	}

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

		if($this->getId() === null){
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
		else{
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

		$this->loadFromArray($oStmt->fetch(\PDO::FETCH_ASSOC));
	}

	private function loadFromArray($aData)
	{
		$aMissingFields = array_diff(self::getDef('aField'), array_keys($aData));

		if(count($aMissingFields))
		{
			trigger_error('Loading array is missing fields: ' . join(', ', $aMissingFields) . '!', E_USER_ERROR);
		}

		$this->aData = array_intersect_key($aData, array_flip(self::getDef('aField')));

		$this->bLoaded = true;
	}
}