<?php
// Newsletter subscription checkbox, injected into the auth dropdown
// via the 'auth-dropdown-extras' slot. $isSubscribed and $currentUser
// are set by the slot renderer in Plugin.php.
?>
                        <label class="auth-newsletter-label">
                            <input type="checkbox" class="auth-newsletter-checkbox" data-user-id="<?= (int) $currentUser['id'] ?>"<?= $isSubscribed ? ' checked' : '' ?>>
                            Subscribe to newsletter
                        </label>
