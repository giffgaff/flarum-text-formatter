<?xml version="1.0" encoding="utf-8"?><xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform"><xsl:output method="html" encoding="utf-8" indent="no"/><xsl:template match="p"><p><xsl:apply-templates/></p></xsl:template><xsl:template match="br"><br/></xsl:template><xsl:template match="et|i|st"/><xsl:template match="URL"><a href="{@url}"><xsl:apply-templates/></a></xsl:template><xsl:template match="YOUTUBE"><iframe width="560" height="315" allowfullscreen="" frameborder="0" scrolling="no"><xsl:attribute name="src">//www.youtube.com/embed/<xsl:value-of select="@id"/><xsl:if test="@list or@t">?<xsl:if test="@list">list=<xsl:value-of select="@list"/><xsl:if test="@t">&amp;</xsl:if></xsl:if><xsl:if test="@t">start=<xsl:value-of select="@t"/></xsl:if></xsl:if></xsl:attribute></iframe></xsl:template></xsl:stylesheet>