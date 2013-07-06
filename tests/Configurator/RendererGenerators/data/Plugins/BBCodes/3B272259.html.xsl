<?xml version="1.0" encoding="utf-8"?><xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform"><xsl:output method="html" encoding="utf-8" indent="no"/><xsl:template match="p"><p><xsl:apply-templates/></p></xsl:template><xsl:template match="br"><br/></xsl:template><xsl:template match="et|i|st"/><xsl:template match="URL"><a href="{@url}"><xsl:copy-of select="@title"/><xsl:apply-templates/></a></xsl:template><xsl:template match="FLASH"><object classid="clsid:D27CDB6E-AE6D-11cf-96B8-444553540000" codebase="http://fpdownload.macromedia.com/get/shockwave/cabs/flash/swflash.cab#version=7,0,0,0" width="{@width}" height="{@height}"><param name="movie" value="{@url}"/><param name="quality" value="high"/><param name="wmode" value="opaque"/><param name="play" value="false"/><param name="loop" value="false"/><param name="allowScriptAccess" value="never"/><param name="allowNetworking" value="internal"/><embed src="{@url}" quality="high" width="{@width}" height="{@height}" wmode="opaque" type="application/x-shockwave-flash" pluginspage="http://www.adobe.com/go/getflashplayer" play="false" loop="false" allowscriptaccess="never" allownetworking="internal"/></object></xsl:template></xsl:stylesheet>