var xslt;
if (typeof DOMParser !== 'undefined' && typeof XSLTProcessor !== 'undefined')
{
	var xslt = new XSLTProcessor;
	xslt['importStylesheet'](loadXML(xsl));

	/**
	* @param {!string} xml
	*/
	function loadXML(xml)
	{
		return (new DOMParser).parseFromString(xml, 'text/xml');
	}

	/**
	* @param {!string} xml
	* @param {!HTMLDocument} targetDoc
	*/
	function transformToFragment(xml, targetDoc)
	{
		// NOTE: importNode() is used because of https://code.google.com/p/chromium/issues/detail?id=266305
		return targetDoc.importNode(xslt['transformToFragment'](loadXML(xml), targetDoc), true)
	}
}
else
{
	var xslt = loadXML(xsl);

	/**
	* @param {!string} xml
	*/
	function loadXML(xml)
	{
		var obj = new ActiveXObject('MSXML2.DOMDocument.3.0');
		obj.async = false;
		obj.validateOnParse = false;
		obj.loadXML(xml);

		return obj;
	}

	/**
	* @param {!string} xml
	* @param {!HTMLDocument} targetDoc
	*/
	function transformToFragment(xml, targetDoc)
	{
		var div = targetDoc.createElement('div'),
			fragment = targetDoc.createDocumentFragment();

		div.innerHTML = loadXML(xml).transformNode(xslt);
		while (div.firstChild)
		{
			fragment.appendChild(div.removeChild(div.firstChild));
		}

		return fragment;
	}
}

var postProcessFunctions = {};

/**
* Parse a given text and render it into given HTML element
*
* @param {!string} text
* @param {!HTMLElement} target
*/
function preview(text, target)
{
	var targetDoc = target.ownerDocument,
		resultFragment = transformToFragment(parse(text), targetDoc);

	// Apply post-processing
	if (HINT.postProcessing)
	{
		var nodes = resultFragment['querySelectorAll']('[data-s9e-livepreview-postprocess]'),
			i     = nodes.length;
		while (--i >= 0)
		{
			/** @type {!string} */
			var code = nodes[i]['getAttribute']('data-s9e-livepreview-postprocess');

			if (!postProcessFunctions[code])
			{
				postProcessFunctions[code] = new Function(code);
			}

			postProcessFunctions[code]['call'](nodes[i]);
		}
	}

	/**
	* Update the content of given element oldEl to match element newEl
	*
	* @param {!HTMLElement} oldEl
	* @param {!HTMLElement} newEl
	*/
	function refreshElementContent(oldEl, newEl)
	{
		var oldNodes = oldEl.childNodes,
			newNodes = newEl.childNodes,
			oldCnt = oldNodes.length,
			newCnt = newNodes.length,
			oldNode,
			newNode,
			left  = 0,
			right = 0;

		// Skip the leftmost matching nodes
		while (left < oldCnt && left < newCnt)
		{
			oldNode = oldNodes[left];
			newNode = newNodes[left];

			if (!refreshNode(oldNode, newNode))
			{
				break;
			}

			++left;
		}

		// Skip the rightmost matching nodes
		var maxRight = Math.min(oldCnt - left, newCnt - left);

		while (right < maxRight)
		{
			oldNode = oldNodes[oldCnt - (right + 1)];
			newNode = newNodes[newCnt - (right + 1)];

			if (!refreshNode(oldNode, newNode))
			{
				break;
			}

			++right;
		}

		// Clone the new nodes
		var newNodesFragment = targetDoc.createDocumentFragment(),
			i = left;

		while (i < (newCnt - right))
		{
			newNode = newNodes[i].cloneNode(true);

			newNodesFragment.appendChild(newNode);
			++i;
		}

		// Remove the old dirty nodes in the middle of the tree
		i = oldCnt - right;
		while (--i >= left)
		{
			oldEl.removeChild(oldNodes[i]);
		}

		// If we haven't skipped any nodes to the right, we can just append the fragment
		if (!right)
		{
			oldEl.appendChild(newNodesFragment);
		}
		else
		{
			oldEl.insertBefore(newNodesFragment, oldEl.childNodes[left]);
		}
	}

	/**
	* Update given node oldNode to make it match newNode
	*
	* @param {!HTMLElement} oldNode
	* @param {!HTMLElement} newNode
	* @return boolean Whether the node can be skipped
	*/
	function refreshNode(oldNode, newNode)
	{
		if (oldNode.nodeName !== newNode.nodeName
		 || oldNode.nodeType !== newNode.nodeType)
		{
			return false;
		}

		// Node.TEXT_NODE || Node.COMMENT_NODE
		if (oldNode.nodeType === 3 || oldNode.nodeType === 8)
		{
			if (oldNode.nodeValue !== newNode.nodeValue)
			{
				oldNode.nodeValue = newNode.nodeValue;
			}

			return true;
		}

		if (oldNode.isEqualNode && oldNode.isEqualNode(newNode))
		{
			return true;
		}

		syncElementAttributes(oldNode, newNode);
		refreshElementContent(oldNode, newNode);

		return true;
	}

	/**
	* Make the set of attributes of given element oldEl match newEl's
	*
	* @param {!HTMLElement} oldEl
	* @param {!HTMLElement} newEl
	*/
	function syncElementAttributes(oldEl, newEl)
	{
		var oldAttributes = oldEl['attributes'],
			newAttributes = newEl['attributes'],
			oldCnt = oldAttributes.length,
			newCnt = newAttributes.length,
			i = oldCnt;

		while (--i >= 0)
		{
			var oldAttr      = oldAttributes[i],
				namespaceURI = oldAttr['namespaceURI'],
				attrName     = oldAttr['name'];

			if (!newEl.hasAttributeNS(namespaceURI, attrName))
			{
				oldEl.removeAttributeNS(namespaceURI, attrName);
			}
		}

		i = newCnt;
		while (--i >= 0)
		{
			var newAttr      = newAttributes[i],
				namespaceURI = newAttr['namespaceURI'],
				attrName     = newAttr['name'],
				attrValue    = newAttr['value'];

			if (attrValue !== oldEl.getAttributeNS(namespaceURI, attrName))
			{
				oldEl.setAttributeNS(namespaceURI, attrName, attrValue);
			}
		}
	}

	refreshElementContent(target, resultFragment);
}

/**
* Set the value of a stylesheet parameter
*
* @param {!string} paramName  Parameter name
* @param {!string} paramValue Parameter's value
*/
function setParameter(paramName, paramValue)
{
	xslt['setParameter'](null, paramName, paramValue);
}