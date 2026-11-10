# Forms

Bedriox exposes Bedrock action, message, and modal forms through `Player::sendForm()`. Form callbacks are tied to the current player session and plugin lifecycle: a response can complete one active form once, and pending forms are removed when the player disconnects or the owning plugin stops.

## Action forms

```php
use Bedriox\Api\Form\ActionForm;
use Bedriox\Api\Form\FormButton;
use Bedriox\Api\Player\Player;

$form = (new ActionForm('Games'))
    ->setBody('Choose a game.')
    ->addButton(
        (new FormButton('Play'))
            ->setId('play_games')
            ->setTexture('textures/ui/play'),
    )
    ->onSubmit(function (Player $player, FormButton $button): void {
        if ($button->id() === 'play_games') {
            $player->sendMessage('Starting the game.');
        }
    });

$player->sendForm($form);
```

`setTexture()` addresses a texture path supplied by the active resource pack. `setImageUrl()` uses a remote image URL. Local filesystem paths are not sent to clients.

## Modal forms

Modal form values are read by stable component ID rather than their wire-array position.

```php
use Bedriox\Api\Form\Component\Dropdown;
use Bedriox\Api\Form\Component\TextField;
use Bedriox\Api\Form\Component\Toggle;
use Bedriox\Api\Form\ModalForm;
use Bedriox\Api\Form\ModalFormResponse;
use Bedriox\Api\Player\Player;

$player->sendForm((new ModalForm('Profile'))
    ->add(new TextField('nickname', 'Nickname'))
    ->add(new Toggle('announcements', 'Receive announcements', true))
    ->add(new Dropdown('theme', 'Theme', ['Purple', 'Blue']))
    ->onSubmit(function (Player $player, ModalFormResponse $response): void {
        $player->sendMessage('Hello ' . $response->getString('nickname'));
    }));
```

Available components are `TextField`, `Toggle`, `Slider`, `StepSlider`, `Dropdown`, `Label`, `Header`, and `Divider`.

## Queue and lifecycle

The default `FormSendMode::QUEUE` waits behind the active form. `REPLACE` closes the active form before showing the replacement. `FAIL_IF_BUSY` returns `null` when another form is active. A successful call returns a `FormHandle`, which exposes `state()`, `isPending()`, and `close()`.

Each session has one active form and at most 16 queued forms. Form definitions, responses, strings, component counts, and callback ownership are bounded by the server. Unknown, stale, duplicate, malformed, and excessive responses never invoke plugin callbacks.
