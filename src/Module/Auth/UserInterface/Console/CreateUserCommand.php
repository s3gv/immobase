<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\UserInterface\Console;

use App\Module\Auth\Application\CheckPassword;
use App\Module\Auth\Domain\PersonName;
use App\Module\Auth\Domain\Rbac\Role;
use App\Module\Auth\Domain\Rbac\RoleName;
use App\Module\Auth\Domain\Rbac\RoleRepository;
use App\Module\Auth\Domain\Rbac\SystemRole;
use App\Module\Auth\Domain\User;
use App\Module\Auth\Domain\UserRepository;
use App\Shared\Contact\Email;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCommand(
    name: 'immobase:user:create',
    description: 'Creates a user; the password is read from standard input. Used by install.sh for the first account.',
)]
final class CreateUserCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly RoleRepository $roles,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly CheckPassword $rules,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email address')
            ->addArgument('givenName', InputArgument::OPTIONAL, 'Given name', '')
            ->addArgument('familyName', InputArgument::OPTIONAL, 'Family name', '')
            ->addOption('admin', null, InputOption::VALUE_NONE, 'Grant administrator rights');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = Email::fromString($this->stringArgument($input, 'email'));

        if (null !== $this->users->findByEmail($email)) {
            $io->error(\sprintf('A user with the address %s already exists.', $email->toString()));

            return Command::FAILURE;
        }

        $password = PasswordFromInput::read($input, $io);
        $refusal = $this->refusalOf($password, $input, $email);

        if (null !== $refusal) {
            $io->error($this->translator->trans($refusal, locale: 'en'));

            return Command::INVALID;
        }

        $this->users->save($this->build($input, $email, $password));

        $io->success(\sprintf('User %s created.', $email->toString()));

        return Command::SUCCESS;
    }

    /**
     * Ein Konto aus der Konsole ist sofort benutzbar.
     *
     * Es gibt niemanden, der es einladen koennte — install.sh legt damit das
     * allererste an, und ein Konto im Zustand "eingeladen" ohne Absender
     * waere eine Sackgasse.
     */
    private function build(InputInterface $input, Email $email, string $password): User
    {
        $user = new User($this->users->nextNumber(), $email);
        $given = trim($this->stringArgument($input, 'givenName'));
        $family = trim($this->stringArgument($input, 'familyName'));

        // Der Name ist freiwillig. Ohne ihn steht die Adresse da, und nachtragen
        // laesst er sich auf der Kontoseite.
        if ('' !== $given && '' !== $family) {
            $user->nameYourself(PersonName::of($given, $family));
        }

        $user->changePassword($this->hasher->hashPassword($user, $password));
        $user->activate();

        $user->assignRoles([$this->roleFor(true === $input->getOption('admin'))]);

        return $user;
    }

    /** Dieselben Regeln wie in der Oberflaeche — das erste Konto ist das, an dem alles haengt. */
    private function refusalOf(string $password, InputInterface $input, Email $email): ?string
    {
        return ($this->rules)($password, [
            $email->toString(),
            $this->stringArgument($input, 'givenName'),
            $this->stringArgument($input, 'familyName'),
        ]);
    }

    /**
     * Jedes Konto braucht eine Rolle — auch das erste aus der Konsole.
     *
     * Ohne --admin entsteht eine rechtefreie Rolle „Mitarbeiter", falls es sie
     * noch nicht gibt. Sie kann nichts, und genau das ist richtig: was das
     * Konto duerfen soll, entscheidet jemand spaeter in der Matrix.
     */
    private function roleFor(bool $administrator): Role
    {
        if ($administrator) {
            return $this->roles->system();
        }

        $name = RoleName::fromString(SystemRole::FALLBACK_NAME);

        return $this->roles->byName($name) ?? $this->create($name);
    }

    private function create(RoleName $name): Role
    {
        $role = Role::named($name);
        $this->roles->save($role);

        return $role;
    }

    /**
     * Liest ein Argument als Zeichenkette.
     *
     * Console-Argumente sind fuer die statische Analyse mixed. Ein blindes
     * (string)-Cast wuerde ein Array stillschweigend zu "Array" machen, statt
     * den Fehler zu zeigen.
     */
    private function stringArgument(InputInterface $input, string $name): string
    {
        $value = $input->getArgument($name);

        if (!\is_string($value)) {
            throw new InvalidArgumentException(\sprintf('The argument "%s" must be a string.', $name));
        }

        return $value;
    }
}
