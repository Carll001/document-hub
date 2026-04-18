<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PdfTextAnchorLocatorService;
use Tests\TestCase;

class PdfTextAnchorLocatorServiceTest extends TestCase
{
    public function test_it_locates_anchor_coordinates_from_bbox_xml(): void
    {
        $service = new PdfTextAnchorLocatorService;
        $xml = <<<'XML'
<!DOCTYPE html>
<html>
  <body>
    <doc>
      <page number="1">
        <word xMin="10" yMin="20" xMax="30" yMax="30">Taxpayer</word>
        <word xMin="32" yMin="20" xMax="50" yMax="30">Name</word>
      </page>
      <page number="2">
        <word xMin="100" yMin="200" xMax="130" yMax="214">Authorized</word>
        <word xMin="132" yMin="200" xMax="180" yMax="214">Signatory</word>
      </page>
    </doc>
  </body>
</html>
XML;

        $hit = $service->locateInBboxXml($xml, 'Authorized Signatory');

        $this->assertIsArray($hit);
        $this->assertSame(2, $hit['page']);
        $this->assertSame(100.0, $hit['x']);
        $this->assertSame(200.0, $hit['y']);
        $this->assertSame(80.0, $hit['width']);
        $this->assertSame(14.0, $hit['height']);
    }

    public function test_it_matches_anchor_text_case_insensitively(): void
    {
        $service = new PdfTextAnchorLocatorService;
        $xml = <<<'XML'
<!DOCTYPE html>
<html>
  <body>
    <doc>
      <page number="3">
        <word xMin="8" yMin="10" xMax="20" yMax="18">authorized</word>
        <word xMin="22" yMin="10" xMax="40" yMax="18">SIGNATORY</word>
      </page>
    </doc>
  </body>
</html>
XML;

        $hit = $service->locateInBboxXml($xml, 'Authorized Signatory');

        $this->assertIsArray($hit);
        $this->assertSame(3, $hit['page']);
    }

    public function test_it_returns_null_when_anchor_does_not_exist(): void
    {
        $service = new PdfTextAnchorLocatorService;
        $xml = <<<'XML'
<!DOCTYPE html>
<html>
  <body>
    <doc>
      <page number="1">
        <word xMin="10" yMin="10" xMax="40" yMax="20">No</word>
        <word xMin="42" yMin="10" xMax="70" yMax="20">Match</word>
      </page>
    </doc>
  </body>
</html>
XML;

        $hit = $service->locateInBboxXml($xml, 'Authorized Signatory');

        $this->assertNull($hit);
    }

    public function test_it_matches_tokens_in_order_with_small_intermediate_gaps(): void
    {
        $service = new PdfTextAnchorLocatorService;
        $xml = <<<'XML'
<!DOCTYPE html>
<html>
  <body>
    <doc>
      <page number="2">
        <word xMin="10" yMin="20" xMax="30" yMax="28">Sole</word>
        <word xMin="32" yMin="20" xMax="50" yMax="28">as</word>
        <word xMin="52" yMin="20" xMax="88" yMax="28">Proprietor</word>
      </page>
    </doc>
  </body>
</html>
XML;

        $hit = $service->locateInBboxXml($xml, 'Sole Proprietor');

        $this->assertIsArray($hit);
        $this->assertSame(2, $hit['page']);
    }

    public function test_it_prefers_the_requested_page_when_anchor_exists_on_multiple_pages(): void
    {
        $service = new PdfTextAnchorLocatorService;
        $xml = <<<'XML'
<!DOCTYPE html>
<html>
  <body>
    <doc>
      <page number="2">
        <word xMin="10" yMin="20" xMax="40" yMax="30">Christopher</word>
        <word xMin="42" yMin="20" xMax="70" yMax="30">Bautista</word>
      </page>
      <page number="3">
        <word xMin="100" yMin="200" xMax="140" yMax="214">Christopher</word>
        <word xMin="142" yMin="200" xMax="180" yMax="214">Bautista</word>
      </page>
    </doc>
  </body>
</html>
XML;

        $hit = $service->locateInBboxXml($xml, 'Christopher Bautista', 3);

        $this->assertIsArray($hit);
        $this->assertSame(3, $hit['page']);
        $this->assertSame(100.0, $hit['x']);
        $this->assertSame(200.0, $hit['y']);
    }

    public function test_it_matches_anchor_tokens_even_with_punctuation_variants(): void
    {
        $service = new PdfTextAnchorLocatorService;
        $xml = <<<'XML'
<!DOCTYPE html>
<html>
  <body>
    <doc>
      <page number="4">
        <word xMin="12" yMin="10" xMax="50" yMax="20">President's</word>
        <word xMin="52" yMin="10" xMax="85" yMax="20">Signature,</word>
      </page>
    </doc>
  </body>
</html>
XML;

        $hit = $service->locateInBboxXml($xml, 'Presidents Signature');

        $this->assertIsArray($hit);
        $this->assertSame(4, $hit['page']);
    }

    public function test_it_matches_anchor_when_a_single_word_contains_multiple_tokens(): void
    {
        $service = new PdfTextAnchorLocatorService;
        $xml = <<<'XML'
<!DOCTYPE html>
<html>
  <body>
    <doc>
      <page number="5">
        <word xMin="30" yMin="42" xMax="92" yMax="56">Sole-Proprietor</word>
      </page>
    </doc>
  </body>
</html>
XML;

        $hit = $service->locateInBboxXml($xml, 'Sole Proprietor');

        $this->assertIsArray($hit);
        $this->assertSame(5, $hit['page']);
    }

    public function test_it_returns_diagnostics_when_anchor_cannot_be_found(): void
    {
        $service = new PdfTextAnchorLocatorService;
        $xml = <<<'XML'
<!DOCTYPE html>
<html>
  <body>
    <doc>
      <page number="1">
        <word xMin="10" yMin="10" xMax="40" yMax="20">Another</word>
        <word xMin="42" yMin="10" xMax="70" yMax="20">Token</word>
      </page>
    </doc>
  </body>
</html>
XML;

        $result = $service->locateInBboxXmlWithDiagnostics($xml, 'Missing Anchor', 1);

        $this->assertNull($result['match']);
        $this->assertSame(['missing', 'anchor'], $result['diagnostics']['normalized_anchor_tokens']);
        $this->assertSame([1], $result['diagnostics']['searched_pages']);
        $this->assertNotEmpty($result['diagnostics']['nearby_tokens']);
    }
}
