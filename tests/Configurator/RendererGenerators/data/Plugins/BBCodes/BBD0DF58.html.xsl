<?xml version="1.0" encoding="utf-8"?><xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform"><xsl:output method="html" encoding="utf-8" indent="no"/><xsl:template match="p"><p><xsl:apply-templates/></p></xsl:template><xsl:template match="br"><br/></xsl:template><xsl:template match="et|i|st"/><xsl:template match="SPOILER"><div class="spoiler"><div class="spoiler-header"><input type="button" value="Montrer" onclick="var s=this.parentNode.nextSibling.style;if(s.display!=''){{s.display='';this.value='Cacher'}}else{{s.display='none';this.value='Montrer'}}"/><span class="spoiler-title">Spoiler : <xsl:value-of select="@title"/></span></div><div class="spoiler-content" style="display:none"><xsl:apply-templates/></div></div></xsl:template></xsl:stylesheet>