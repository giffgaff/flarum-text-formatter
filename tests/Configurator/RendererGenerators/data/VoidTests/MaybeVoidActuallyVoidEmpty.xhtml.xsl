<?xml version="1.0" encoding="utf-8"?><xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform"><xsl:output method="xml" encoding="utf-8" indent="no" omit-xml-declaration="yes"/><xsl:template match="p"><p><xsl:apply-templates/></p></xsl:template><xsl:template match="br"><br/></xsl:template><xsl:template match="et|i|st"/><xsl:template match="FOO"><xsl:element name="{@name}"><xsl:attribute name="id">foo</xsl:attribute></xsl:element></xsl:template></xsl:stylesheet>