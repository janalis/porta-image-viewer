Feature: The image viewer
  Opening a picture shows it as an image tab instead of text: zoomed to fit
  at first, then in and out along a ladder of levels, at its actual size,
  and panned with the arrows. An SVG opens as its markup with the picture a
  converter draws beside it. Here pictures are half-blocks (PORTA_GRAPHICS is
  off), and the converter is the stand-in the PHPUnit suites use.

  Background:
    Given a PHP project
    And the bundled plugins are available

  Scenario: A picture opens fitted to the pane, its size on the status bar
    Given an image "img.png" of 64 by 64 pixels
    And I start the app with the arguments "img.png"
    Then I should see "64 × 64"
    And I should see "Fit ("
    And I should see "▀"
    And the element "#image-viewer" should be focused

  Scenario: Zooming in, out, to the actual size and back to fit
    Given an image "img.png" of 64 by 64 pixels
    And I start the app with the arguments "img.png"
    Then I should see "Fit ("
    When I use the action "image_zoom_actual"
    Then I should see "64 × 64  100%"
    When I use the action "image_zoom_in"
    Then I should see "64 × 64  200%"
    When I use the action "image_zoom_out"
    Then I should see "64 × 64  100%"
    When I use the action "image_zoom_fit"
    Then I should see "64 × 64  Fit ("

  Scenario: The arrows pan a picture larger than the pane
    Given an image "big.png" of 400 by 400 pixels
    And I start the app with the arguments "big.png"
    When I use the action "image_zoom_actual"
    Then I should see "400 × 400  100%"
    When I note which part of the picture is in view
    And I use the action "image_pan_right"
    Then the view should have moved right
    When I note which part of the picture is in view
    And I use the action "image_pan_down"
    Then the view should have moved down
    When I note which part of the picture is in view
    And I use the action "image_pan_left"
    Then the view should have moved left
    When I note which part of the picture is in view
    And I use the action "image_pan_up"
    Then the view should have moved up

  @setting:image-viewer.maxBytes
  Scenario: A picture larger than the size limit is not opened as one
    Given an image "img.png" of 64 by 64 pixels
    And the project configuration:
      """
      {"plugins": {"image-viewer": {"maxBytes": 10}}}
      """
    And I start the app with the arguments "img.png"
    Then I should see "Not a UTF-8 text file"
    And I should not see "64 × 64"

  @setting:image-viewer.svgConverter
  Scenario: An SVG opens as its markup, beside the picture the converter draws
    Given only PHP is on the PATH
    And the project configuration naming the test fakes:
      """
      {"plugins": {"image-viewer": {"svgConverter": "%fakes%/fake-rsvg"}}}
      """
    And a file "logo.svg" containing:
      """
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 50"><rect width="100" height="50" fill="#284"/></svg>
      """
    And I start the app with the arguments "logo.svg"
    Then I should see "Editor  [Split]  Preview"
    And the editor should contain "viewBox"
    When I use the action "preview_only"
    Then I should see "100 × 50"
    And the element "#image-viewer" should be focused

  Scenario: With no converter, an SVG stays plain markup
    Given only PHP is on the PATH
    And a file "logo.svg" containing:
      """
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 50"><rect width="100" height="50" fill="#284"/></svg>
      """
    And I start the app with the arguments "logo.svg"
    Then the editor should contain "viewBox"
    And I should not see "[Split]"

  @setting:image-viewer.svg
  Scenario: Turning the SVG preview off opens an SVG as plain markup
    Given only PHP is on the PATH
    And the project configuration naming the test fakes:
      """
      {"plugins": {"image-viewer": {"svgConverter": "%fakes%/fake-rsvg", "svg": false}}}
      """
    And a file "logo.svg" containing:
      """
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 50"><rect width="100" height="50" fill="#284"/></svg>
      """
    And I start the app with the arguments "logo.svg"
    Then the editor should contain "viewBox"
    And I should not see "[Split]"

  Scenario: Open as Text on a picture says its bytes are no text, and keeps the picture
    Given an image "img.png" of 64 by 64 pixels
    And I start the app with the arguments "img.png"
    Then I should see "64 × 64"
    When I use the action "open_as_text"
    Then I should see "Not a UTF-8 text file"
    And I should see "64 × 64"
    And the element "#image-viewer" should be focused
