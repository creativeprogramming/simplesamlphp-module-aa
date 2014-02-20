<?php

/* simpleSAMLphp code here */
$aa_config = SimpleSAML_Configuration::getConfig('module_aa.php'); 
$metadata = SimpleSAML_Metadata_MetaDataStorageHandler::getMetadataHandler();

/* Receiving the attribute query */
$binding = SAML2_Binding::getCurrentBinding();
SimpleSAML_Logger::debug('[aa] binding: '.var_export($binding,true));

$query = $binding->receive();

SimpleSAML_Logger::debug('[aa] query: '.var_export($query,true));

if (!($query instanceof SAML2_AttributeQuery)) {
	throw new SimpleSAML_Error_BadRequest('Invalid message received to AttributeQuery endpoint.');
}


/* Getting the related entities metadata objects */
$aaEntityId = $metadata->getMetaDataCurrentEntityID('attributeauthority-hosted');
$aaMetadata = $metadata->getMetadataConfig($aaEntityId, 'attributeauthority-hosted');

$spEntityId = $query->getIssuer();
if ($spEntityId === NULL) {
	throw new SimpleSAML_Error_BadRequest('Missing <saml:Issuer> in <samlp:AttributeQuery>.');
}
$spMetadata = $metadata->getMetaDataConfig($spEntityId, 'saml20-sp-remote');

// validate signed query

if (! sspmod_saml_Message::checkSign($spMetadata,$query)){
	throw new SimpleSAML_Error_Exception("[aa] The sign of the AttributeQuery is wrong!");

}


/* The endpoint we should deliver the message to. */
/* legacy support get out very soon! */
$endpoint=NULL;
if ($binding instanceof SAML2_HTTPRedirect ){
  $endpoint = $spMetadata->getString('testAttributeEndpoint');
}

/* The attributes we will return. */
$nameId=$query->getNameId();
$expected_nameFormat = $aa_config->getValue('expected_nameFormat',SAML2_Const::NAMEID_PERSISTENT);
if ($aa_config->hasValue('expected_nameFormat') && ($nameId['nameFormat'] != $expected_nameFormat)){
	throw new SimpleSAML_Error_BadRequest('Bad news! NameIdFormat is not the expected (persistent is recommended)! Given: '.$nameId['nameFormat'].' expected: '. $expected_nameFormat);
}
$resolverclass = 'sspmod_aa_AttributeResolver_'.$aa_config->getValue('resolver');
 if (! class_exists($resolverclass)){
	throw new SimpleSAML_Error_Exception('[aa] There is no resolver named '.$aa_config->getValue('resolver').' in the config/module_aa.php');
}

$ar = new $resolverclass($aa_config);
$attributes = array();
$attributes = $ar->getAttributes($nameId['Value'],$spEntityId);

/* for testing only */
if ($aa_config->hasValue('testvalue')){
  $attributes=array_merge($attributes,$aa_config->getValue('testvalue'));
}

/* The name format of the attributes. */
// gyufi $attributeNameFormat = SAML2_Const::NAMEFORMAT_UNSPECIFIED;
$attributeNameFormat = 'urn:oasis:names:tc:SAML:2.0:attrname-format:uri';

SimpleSAML_Logger::debug('[aa] Got relay state: '.$query->getRelayState());

/* Determine which attributes we will return. */
$returnAttributes = $query->getAttributes();
if (count($returnAttributes) === 0) {
	SimpleSAML_Logger::debug('[aa] No attributes requested - return all attributes: '.var_export($attributes,true));
	$returnAttributes = $attributes;

} elseif ($query->getAttributeNameFormat() !== $attributeNameFormat) {
	SimpleSAML_Logger::debug('[aa] Requested attributes with wrong NameFormat - no attributes returned. Expected: '.$attributeNameFormat.' Got: '. $query->getAttributeNameFormat());
	$returnAttributes = array();
} else {
	foreach ($returnAttributes as $name => $values) {
		SimpleSAML_Logger::debug('[aa] Check this attribute: '.$name);
		if (!array_key_exists($name, $attributes)) {
			/* We don't have this attribute. */
			SimpleSAML_Logger::debug('[aa] We dont have this attribute, unset: '.$name);
			unset($returnAttributes[$name]);
			continue;
		}

		if (count($values) === 0) {
			/* Return all attributes. */
			$returnAttributes[$name] = $attributes[$name];
			continue;
		}

		/* Filter which attribute values we should return. */
		$returnAttributes[$name] = array_intersect($values, $attributes[$name]);
	}
}


/* $returnAttributes contains the attributes we should return. Send them. */
$assertion = new SAML2_Assertion();
$assertion->setIssuer($aaEntityId);
$assertion->setNameId($query->getNameId());
$assertion->setNotBefore(time() - $aa_config->getInteger('timewindow'));
$assertion->setNotOnOrAfter(time() + $aa_config->getInteger('timewindow'));
$assertion->setValidAudiences(array($spEntityId));
$assertion->setAttributes($returnAttributes);
$assertion->setAttributeNameFormat($attributeNameFormat);

$sc = new SAML2_XML_saml_SubjectConfirmation();
$sc->Method = SAML2_Const::CM_BEARER;
$sc->SubjectConfirmationData = new SAML2_XML_saml_SubjectConfirmationData();
$sc->SubjectConfirmationData->NotOnOrAfter = time() + $aa_config->getInteger('timewindow');
$sc->SubjectConfirmationData->Recipient = $endpoint;
$sc->SubjectConfirmationData->InResponseTo = $query->getId();
$assertion->setSubjectConfirmation(array($sc));

sspmod_saml_Message::addSign($aaMetadata, $spMetadata, $assertion);

$response = new SAML2_Response();
$response->setRelayState($query->getRelayState());
$response->setDestination($endpoint);
$response->setIssuer($aaEntityId);
$response->setInResponseTo($query->getId());
$response->setAssertions(array($assertion));
sspmod_saml_Message::addSign($aaMetadata, $spMetadata, $response);

SimpleSAML_Logger::debug('[aa] Sending: '.var_export($response,true));
$binding->send($response);
