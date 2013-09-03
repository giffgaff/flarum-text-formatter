/*
 * Copyright 2008 Google Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

// This file was auto-generated.
// See http://code.google.com/p/closure-compiler/source/browse/trunk/externs/ for the original source.
// See https://github.com/s9e/TextFormatter/blob/master/scripts/generateExterns.php for details.

/**
 * @type {undefined}
 * @const
 */
var undefined;
/**
 * @param {string} uri
 * @return {string}
 * @nosideeffects
 */
function encodeURIComponent(uri) {}
/**
 * @param {string} str
 * @return {string}
 * @nosideeffects
 */
function escape(str) {}
/**
 * @param {*} num
 * @return {boolean}
 * @nosideeffects
 */
function isNaN(num) {}
/**
 * @param {*} num
 * @param {number|undefined} base
 * @return {number}
 * @nosideeffects
 */
function parseInt(num, base) {}
/**
 * @constructor
 * @param {*=} opt_value
 * @nosideeffects
 */
function Object(opt_value) {}
/**
 * @this {*}
 * @return {string}
 * @nosideeffects
 */
Object.prototype.toString = function() {};
/**
 * @constructor
 * @param {...*} var_args
 * @return {!Array}
 * @nosideeffects
 */
function Array(var_args) {}
/**
 * @param {?function(this:S, T, number, !Array.<T>): ?} callback
 * @param {S=} opt_thisobj
 * @this {{length: number}|Array.<T>|string}
 * @template T,S
 */
Array.prototype.forEach = function(callback, opt_thisobj) {};
/**
 * @param {T} obj
 * @param {number=} opt_fromIndex
 * @return {number}
 * @this {{length: number}|Array.<T>|string}
 * @nosideeffects
 * @template T
 */
Array.prototype.indexOf = function(obj, opt_fromIndex) {};
/**
 * @param {*=} opt_separator Specifies a string to separate each element of the
 * @return {string}
 * @this {{length: number}|string}
 * @nosideeffects
 */
Array.prototype.join = function(opt_separator) {};
/**
 * @type {number}
 */
Array.prototype.length;
/**
 * @return {T}
 * @this {{length: number}|Array.<T>}
 * @modifies {this}
 * @template T
 */
Array.prototype.pop = function() {};
/**
 * @param {...T} var_args
 * @return {number} The new length of the array.
 * @this {{length: number}|Array.<T>}
 * @template T
 * @modifies {this}
 */
Array.prototype.push = function(var_args) {};
/**
 * @this {{length: number}}
 * @modifies {this}
 */
Array.prototype.reverse = function() {};
/**
 * @param {*=} opt_begin Zero-based index at which to begin extraction.  A
 * @param {*=} opt_end Zero-based index at which to end extraction.  slice
 * @return {!Array.<T>}
 * @this {{length: number}|Array.<T>|string}
 * @template T
 * @nosideeffects
 */
Array.prototype.slice = function(opt_begin, opt_end) {};
/**
 * @param {function(T,T):number=} opt_compareFunction Specifies a function that
 * @this {{length: number}|Array.<T>}
 * @template T
 */
Array.prototype.sort = function(opt_compareFunction) {};
/**
 * @param {*=} opt_index Index at which to start changing the array. If negative,  *     will begin that many elements from the end.  A non-number type will be
 * @param {*=} opt_howMany An integer indicating the number of old array elements
 * @param {...T} var_args
 * @return {!Array.<T>}
 * @this {{length: number}|Array.<T>}
 * @modifies {this}
 * @template T
 */
Array.prototype.splice = function(opt_index, opt_howMany, var_args) {};
/**
 * @param {?=} opt_yr_num
 * @param {?=} opt_mo_num
 * @param {?=} opt_day_num
 * @param {?=} opt_hr_num
 * @param {?=} opt_min_num
 * @param {?=} opt_sec_num
 * @param {?=} opt_ms_num
 * @constructor
 * @return {string}
 * @nosideeffects
 */
function Date(opt_yr_num, opt_mo_num, opt_day_num, opt_hr_num, opt_min_num,     opt_sec_num, opt_ms_num) {}
/**
 * @param {*} date
 * @return {number}
 * @nosideeffects
 */
Date.parse = function(date) {};
/**
 * @const
 */
var Math = {};
/**
 * @param {*} x
 * @return {number}
 * @nosideeffects
 */
Math.floor = function(x) {};
/**
 * @param {...*} var_args
 * @return {number}
 * @nosideeffects
 */
Math.max = function(var_args) {};
/**
 * @param {...*} var_args
 * @return {number}
 * @nosideeffects
 */
Math.min = function(var_args) {};
/**
 * @return {number}
 * @nosideeffects
 */
Math.random = function() {};
/**
 * @constructor
 * @param {*=} opt_value
 * @return {number}
 * @nosideeffects
 */
function Number(opt_value) {}
/**
 * @this {Number|number}
 * @param {(number|Number)=} opt_radix An optional radix.
 * @return {string}
 * @nosideeffects
 * @override
 */
Number.prototype.toString = function(opt_radix) {};
/**
 * @constructor
 * @param {*=} opt_pattern
 * @param {*=} opt_flags
 * @return {!RegExp}
 * @nosideeffects
 */
function RegExp(opt_pattern, opt_flags) {}
/**
 * @param {*} str The string to search.
 * @return {Array.<string>} This should really return an Array with a few
 */
RegExp.prototype.exec = function(str) {};
/**
 * @type {number}
 */
RegExp.prototype.lastIndex;
/**
 * @param {*} str The string to search.
 * @return {boolean} Whether the string was matched.
 */
RegExp.prototype.test = function(str) {};
/**
 * @constructor
 * @param {*=} opt_str
 * @return {string}
 * @nosideeffects
 */
function String(opt_str) {}
/**
 * @param {...number} var_args
 * @return {string}
 * @nosideeffects
 */
String.fromCharCode = function(var_args) {};
/**
 * @this {String|string}
 * @param {number} index
 * @return {string}
 * @nosideeffects
 */
String.prototype.charAt = function(index) {};
/**
 * @this {String|string}
 * @param {number=} opt_index
 * @return {number}
 * @nosideeffects
 */
String.prototype.charCodeAt = function(opt_index) {};
/**
 * @this {String|string}
 * @param {string|null} searchValue
 * @param {(number|null)=} opt_fromIndex
 * @return {number}
 * @nosideeffects
 */
String.prototype.indexOf = function(searchValue, opt_fromIndex) {};
/**
 * @type {number}
 */
String.prototype.length;
/**
 * @this {String|string}
 * @param {RegExp|string} regex
 * @param {string|Function} str
 * @param {string=} opt_flags
 * @return {string}
 */
String.prototype.replace = function(regex, str, opt_flags) {};
/**
 * @this {String|string}
 * @param {*=} opt_separator
 * @param {number=} opt_limit
 * @return {!Array.<string>}
 * @nosideeffects
 */
String.prototype.split = function(opt_separator, opt_limit) {};
/**
 * @this {String|string}
 * @param {number} start
 * @param {number=} opt_length
 * @return {string} The specified substring.
 * @nosideeffects
 */
String.prototype.substr = function(start, opt_length) {};
/**
 * @this {String|string}
 * @return {string}
 * @nosideeffects
 */
String.prototype.toLowerCase = function() {};
/**
 * @this {String|string}
 * @return {string}
 * @nosideeffects
 */
String.prototype.toUpperCase = function() {};
/**
 * @param {Node} externalNode
 * @param {boolean} deep
 * @return {Node}
 */
Document.prototype.importNode = function(externalNode, deep) {};
/**
 * @type {string}
 * @implicitCast
 */
Element.prototype.innerHTML;
/**
 * @constructor
 */
function DOMParser() {}
/**
 * @param {string} src The UTF16 string to be parsed.
 * @param {string} type The content type of the string.
 * @return {Document}
 */
DOMParser.prototype.parseFromString = function(src, type) {};
/**
 * @constructor
 * @extends {Node}
 */
function Document() {}
/**
 * @return {!DocumentFragment}
 * @nosideeffects
 */
Document.prototype.createDocumentFragment = function() {};
/**
 * @param {string} tagName
 * @return {!Element}
 */
Document.prototype.createElement = function(tagName) {};
/**
 * @constructor
 * @extends {Node}
 */
function DocumentFragment() {}
/**
 * @constructor
 */
function NamedNodeMap() {}
/**
 * @param {number} index
 * @return {Node}
 * @nosideeffects
 */
NamedNodeMap.prototype.item = function(index) {};
/**
 * @type {number}
 */
NamedNodeMap.prototype.length;
/**
 * @constructor
 */
function Node() {}
/**
 * @param {Node} newChild
 * @return {Node}
 */
Node.prototype.appendChild = function(newChild) {};
/**
 * @type {!NodeList}
 */
Node.prototype.childNodes;
/**
 * @param {boolean} deep
 * @return {Node}
 * @nosideeffects
 */
Node.prototype.cloneNode = function(deep) {};
/**
 * @param {Node} newChild
 * @param {Node?} refChild
 * @return {Node}
 */
Node.prototype.insertBefore = function(newChild, refChild) {};
/**
 * @type {string}
 */
Node.prototype.nodeName;
/**
 * @type {number}
 */
Node.prototype.nodeType;
/**
 * @type {string}
 */
Node.prototype.nodeValue;
/**
 * @type {Document}
 */
Node.prototype.ownerDocument;
/**
 * @type {Node}
 */
Node.prototype.parentNode;
/**
 * @param {Node} oldChild
 * @return {Node}
 */
Node.prototype.removeChild = function(oldChild) {};
/**
 * @constructor
 */
function NodeList() {}
/**
 * @type {number}
 */
NodeList.prototype.length;
/**
 * @constructor
 * @extends {Node}
 */
function Element() {}
/**
 * @param {string} namespaceURI
 * @param {string} localName
 * @return {string}
 * @nosideeffects
 */
Element.prototype.getAttributeNS = function(namespaceURI, localName) {};
/**
 * @param {string} namespaceURI
 * @param {string} localName
 * @return {boolean}
 * @nosideeffects
 */
Element.prototype.hasAttributeNS = function(namespaceURI, localName) {};
/**
 * @param {string} namespaceURI
 * @param {string} localName
 * @return {undefined}
 */
Element.prototype.removeAttributeNS = function(namespaceURI, localName) {};
/**
 * @param {string} namespaceURI
 * @param {string} qualifiedName
 * @param {string|number|boolean} value Values are converted to strings with
 * @return {undefined}
 */
Element.prototype.setAttributeNS = function(namespaceURI, qualifiedName, value) {};
/**
 * @param {Node} arg
 * @return {boolean}
 * @nosideeffects
 */
Node.prototype.isEqualNode = function(arg) {};
/**
 * @type {string}
 */
Node.prototype.namespaceURI;
/**
 * @type {string}
 * @implicitCast
 */
Node.prototype.textContent;
