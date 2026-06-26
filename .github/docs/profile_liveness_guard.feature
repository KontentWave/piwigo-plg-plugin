# Filename: profile_liveness_guard.feature

Feature: Profile liveness guard
  As a gallery administrator
  I want public profiles to confirm ownership regularly by SMS
  So that inactive or abandoned profile galleries are hidden automatically

  Background:
    Given the CPT plugin is enabled
    And the Two Factor SMS plugin is enabled
    And the Profile Liveness Guard plugin is enabled
    And SMSTOOLS API credentials are configured
    And a registered user "gallery_owner" exists with password "password123"
    And "gallery_owner" is not an admin or webmaster
    And "gallery_owner" owns a public root album named "slecna1" through Community
    And the album "slecna1" has a public subalbum named "slecna1_album1"
    And "gallery_owner" has verification phone "+421905000000"

  Scenario: Owner sees current liveness status in UCP
    Given "gallery_owner" is currently verified for profile liveness
    And I am logged in as "gallery_owner"
    When I go to my profile page
    Then I should see a "Profile Verification" section
    And I should see when my profile was last verified
    And I should see when the next verification is due

  Scenario: Owner confirms liveness with SMS code
    Given I am logged in as "gallery_owner"
    And I am on my profile page
    When I request a profile verification SMS
    Then an SMS OTP should be sent to "+421905000000"
    And I should see a masked phone number

    When I enter the correct profile verification code
    Then my profile liveness status should become "verified"
    And the next verification date should be about 7 days later

  Scenario: Due scan sends SMS for due profile
    Given "gallery_owner" has a liveness record due now
    When the Profile Liveness Guard due scan runs
    Then an SMS OTP should be sent to "+421905000000"
    And the liveness status should become "sms_sent"
    And the liveness expiry should be set about 48 hours later

  Scenario: Re-running due scan does not send duplicate SMS during grace period
    Given "gallery_owner" has a liveness status of "sms_sent"
    And the liveness challenge has not expired
    When the Profile Liveness Guard due scan runs again
    Then no duplicate SMS should be sent
    And the original expiry should remain unchanged

  Scenario: Expired profile is made private
    Given "gallery_owner" has a liveness status of "sms_sent"
    And the liveness challenge expired yesterday
    When the Profile Liveness Guard due scan runs
    Then CPT should make the album tree for "slecna1" private
    And the liveness status should become "albums_privatized"

    When I browse as a guest
    Then I should not see the album "slecna1"
    And I should not see the album "slecna1_album1"

  Scenario: Late confirmation does not automatically restore public visibility
    Given "gallery_owner" has a liveness status of "albums_privatized"
    And I am logged in as "gallery_owner"
    When I enter the correct late verification code
    Then my liveness status should become "awaiting_admin_restore"
    And the album tree for "slecna1" should remain private

  Scenario: Admin restores profile after late confirmation
    Given "gallery_owner" has a liveness status of "awaiting_admin_restore"
    And I am logged in as a webmaster
    When I approve restoring the profile for "gallery_owner"
    Then the liveness status should become "verified"
    And the admin action should be logged

  Scenario: Webmaster is excluded from the owner liveness workflow
    Given I am logged in as a webmaster
    When I go to my profile page
    Then I should not see a "Profile Verification" section

  Scenario Outline: Owner sees PLG section localized through LanguageSwitch
    Given "gallery_owner" is currently verified for profile liveness
    And I am logged in as "gallery_owner"
    And the active gallery language is "<locale>"
    When I go to my profile page
    Then I should see a "<section_title>" section

    Examples:
      | locale | section_title       |
      | en_UK  | Profile Verification |
      | es_ES  | Verificación del perfil |
      | hu_HU  | Profilellenőrzés |
      | sk_SK  | Overenie profilu |
      | ru_RU  | Проверка профиля |
      | uk_UA  | Перевірка профілю |
      | zh_CN  | 资料验证 |

  Scenario: Visitor cannot trigger liveness SMS
    Given I am browsing as a guest
    When I submit a crafted request to send a liveness SMS for "gallery_owner"
    Then the request should be rejected
    And no SMS should be sent to "+421905000000"

Scenario: Expired mixed-privacy profile records a visibility snapshot before privatization
    Given "gallery_owner" has a liveness status of "sms_sent"
    And the liveness challenge expired yesterday
    And the root album "slecna1" is public
    And the album "slecna1_album1" is private
    And the album "slecna1_album2" is shared with user "trusted_viewer"
    When the Profile Liveness Guard due scan runs
    Then PLG should save a visibility snapshot for the owner album tree
    And the snapshot for "slecna1" should say "public"
    And the snapshot for "slecna1_album1" should say "private"
    And the snapshot for "slecna1_album2" should say "shared" with user "trusted_viewer"
    And CPT should make the effective owner album tree private
    And the liveness status should become "albums_privatized"

  Scenario: Admin restore returns albums to their saved privacy after late confirmation
    Given "gallery_owner" has a liveness status of "albums_privatized"
    And PLG captured a visibility snapshot before privatization
    And the snapshot says album "slecna1" was public
    And the snapshot says album "slecna1_album1" was private
    And the snapshot says album "slecna1_album2" was shared with user "trusted_viewer"
    And I am logged in as "gallery_owner"
    When I enter the correct late verification code
    Then my liveness status should become "awaiting_admin_restore"

    Given I am logged in as a webmaster
    When I approve restoring the profile for "gallery_owner"
    Then the album "slecna1" should be public
    And the album "slecna1_album1" should remain private
    And the album "slecna1_album2" should be shared with user "trusted_viewer"
    And the liveness status should become "verified"
    And the snapshot restore should be logged

  Scenario: Admin restore is blocked when no privacy snapshot exists
    Given "gallery_owner" has a liveness status of "awaiting_admin_restore"
    And no visibility snapshot exists for "gallery_owner"
    And I am logged in as a webmaster
    When I approve restoring the profile for "gallery_owner"
    Then the restore should be rejected as unsafe
    And no album should be made public by PLG
    And the admin should see a missing snapshot warning

  Scenario: Snapshot is not overwritten by a repeated expiry scan
    Given "gallery_owner" has a liveness status of "sms_sent"
    And the liveness challenge expired yesterday
    And PLG already captured a visibility snapshot for this guard record
    When the Profile Liveness Guard due scan runs again
    Then PLG should not overwrite the existing visibility snapshot
    And no duplicate snapshot rows should be created

Scenario: PLG uses the previously verified Two Factor phone after CPT phone changes
    Given owner "gallery_owner" has CPT contact number "+421905111111"
    And "gallery_owner" has verified SMS phone "+421905111111" in Two Factor
    And "gallery_owner" changes the CPT contact number to "+421905222222"
    And the new CPT contact number has not been verified yet
    When the Profile Liveness Guard sends a liveness SMS
    Then the SMS should be sent to the verified Two Factor phone "+421905111111"
    And no SMS should be sent to the raw CPT contact number "+421905222222"

  Scenario: PLG uses the new phone only after Two Factor verification succeeds
    Given owner "gallery_owner" has verified SMS phone "+421905111111" in Two Factor
    And "gallery_owner" changes the CPT contact number to "+421905222222"
    And "gallery_owner" successfully verifies "+421905222222" through Two Factor SMS
    When the Profile Liveness Guard sends the next liveness SMS
    Then the SMS should be sent to the verified Two Factor phone "+421905222222"

  Scenario: PLG refuses liveness SMS when no verified Two Factor SMS phone exists
    Given owner "gallery_owner" has CPT contact number "+421905222222"
    And "gallery_owner" has no verified SMS phone in Two Factor
    When "gallery_owner" requests a liveness SMS
    Then PLG should reject the request
    And PLG should tell the owner to verify the phone in Two Factor first
    And no SMS should be sent to the raw CPT contact number
