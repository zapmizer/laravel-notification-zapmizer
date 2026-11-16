<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use NotificationChannels\Zapmizer\Install\ConnectableModel;
use PHPUnit\Framework\TestCase;

class ConnectableModelTest extends TestCase
{
    private const LARAVEL_11_USER = <<<'PHP'
<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
    ];

    protected function casts(): array
    {
        return ['password' => 'hashed'];
    }
}

PHP;

    public function testItAddsTheInterfaceTheTraitAndTheImportsToALaravelUser()
    {
        $model = new ConnectableModel(self::LARAVEL_11_USER, 'User');

        $this->assertTrue($model->recognized());
        $this->assertFalse($model->implementsInterface());
        $this->assertFalse($model->usesTrait());

        $expected = <<<'PHP'
<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use NotificationChannels\Zapmizer\Connectable as ConnectsZapmizer;
use NotificationChannels\Zapmizer\Contracts\Connectable;

class User extends Authenticatable implements Connectable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;
    use ConnectsZapmizer;

    protected $fillable = [
        'name',
        'email',
    ];

    protected function casts(): array
    {
        return ['password' => 'hashed'];
    }
}

PHP;

        $this->assertSame($expected, $model->withConnectable());
    }

    public function testTheEditIsIdempotent()
    {
        $once = (new ConnectableModel(self::LARAVEL_11_USER, 'User'))->withConnectable();
        $again = new ConnectableModel($once, 'User');

        $this->assertTrue($again->implementsInterface());
        $this->assertTrue($again->usesTrait());
        $this->assertSame($once, $again->withConnectable());
    }

    public function testAnExistingImplementsListGetsTheInterfaceAppended()
    {
        $code = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Auth\User as Authenticatable;

final class Team extends Authenticatable
    implements MustVerifyEmail
{
}
PHP;

        $edited = (new ConnectableModel($code, 'Team'))->withConnectable();

        $this->assertStringContainsString("final class Team extends Authenticatable\n    implements MustVerifyEmail, Connectable\n{\n    use ConnectsZapmizer;\n}", $edited);
        $this->assertStringContainsString("use Illuminate\\Foundation\\Auth\\User as Authenticatable;\nuse NotificationChannels\\Zapmizer\\Connectable as ConnectsZapmizer;\nuse NotificationChannels\\Zapmizer\\Contracts\\Connectable;\n", $edited);
    }

    public function testOnlyTheMissingHalfIsAdded()
    {
        $code = <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use NotificationChannels\Zapmizer\Connectable as ConnectsZapmizer;

class Team extends Model
{
    use ConnectsZapmizer;
}
PHP;

        $model = new ConnectableModel($code, 'Team');
        $this->assertTrue($model->usesTrait());
        $this->assertFalse($model->implementsInterface());

        $edited = $model->withConnectable();

        $this->assertSame(1, substr_count($edited, 'use ConnectsZapmizer;'));
        $this->assertSame(1, substr_count($edited, 'as ConnectsZapmizer;'), 'no second import of the trait');
        $this->assertStringContainsString('class Team extends Model implements Connectable', $edited);
    }

    public function testFullyQualifiedNamesCountAsDone()
    {
        $code = <<<'PHP'
<?php

namespace App\Models;

class Team extends \Illuminate\Database\Eloquent\Model implements \NotificationChannels\Zapmizer\Contracts\Connectable
{
    use \NotificationChannels\Zapmizer\Connectable;
}
PHP;

        $model = new ConnectableModel($code, 'Team');

        $this->assertTrue($model->implementsInterface());
        $this->assertTrue($model->usesTrait());
        $this->assertSame($code, $model->withConnectable());
    }

    public function testATakenConnectableNameFallsBackToAnAlias()
    {
        $code = <<<'PHP'
<?php

namespace App\Models;

use App\Contracts\Connectable;
use Illuminate\Database\Eloquent\Model;

class Team extends Model implements Connectable
{
}
PHP;

        $edited = (new ConnectableModel($code, 'Team'))->withConnectable();

        $this->assertStringContainsString('class Team extends Model implements Connectable, ZapmizerConnectable', $edited);
        $this->assertStringContainsString('use NotificationChannels\Zapmizer\Contracts\Connectable as ZapmizerConnectable;', $edited);
    }

    public function testAFileWithoutImportsGetsThemAfterTheNamespace()
    {
        $code = "<?php\n\nnamespace App;\n\nclass Account extends \\Illuminate\\Database\\Eloquent\\Model\n{\n    protected \$guarded = [];\n}\n";

        $edited = (new ConnectableModel($code, 'Account'))->withConnectable();

        $this->assertStringStartsWith("<?php\n\nnamespace App;\n\nuse NotificationChannels\\Zapmizer\\Connectable as ConnectsZapmizer;\nuse NotificationChannels\\Zapmizer\\Contracts\\Connectable;\n\nclass Account", $edited);
        $this->assertStringContainsString("{\n    use ConnectsZapmizer;\n    protected \$guarded = [];", $edited);
    }

    public function testShapesItDoesNotRecognizeAreLeftAlone()
    {
        $shapes = [
            'no namespace' => "<?php\n\nclass User {}\n",
            'braced namespace' => "<?php\n\nnamespace App\\Models {\n    class User {}\n}\n",
            'two classes' => "<?php\n\nnamespace App\\Models;\n\nclass User {}\nclass Other {}\n",
            'another class name' => "<?php\n\nnamespace App\\Models;\n\nclass Member {}\n",
        ];

        foreach ($shapes as $label => $code) {
            $model = new ConnectableModel($code, 'User');

            $this->assertFalse($model->recognized(), $label);
            $this->assertNull($model->withConnectable(), $label);
        }
    }

    public function testATraitWithAnAdaptationBlockGetsTheNewUseAfterTheBrace()
    {
        $code = <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Team extends Model
{
    use SomeTrait {
        foo as bar;
    }
}
PHP;

        $edited = (new ConnectableModel($code, 'Team'))->withConnectable();

        $this->assertStringContainsString("{\n    use ConnectsZapmizer;\n    use SomeTrait {", $edited);
    }

    public function testTheSnippetNamesBothPieces()
    {
        $snippet = ConnectableModel::snippet('Team');

        $this->assertStringContainsString('use NotificationChannels\Zapmizer\Contracts\Connectable;', $snippet);
        $this->assertStringContainsString('use NotificationChannels\Zapmizer\Connectable as ConnectsZapmizer;', $snippet);
        $this->assertStringContainsString('class Team extends ... implements Connectable', $snippet);
    }
}
