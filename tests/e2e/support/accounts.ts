import path from 'node:path';

// Accounts of the fixtures (src/Ladb/CoreBundle/DataFixtures/UserFixtures.php): the password is
// the username
export const ADMIN = 'admin';
export const USER = 'user';
export const USER2 = 'user2';

export const ACCOUNTS = [ADMIN, USER, USER2];

/**
 * The cookies of a logged in account, written by auth.setup.ts: test.use({ storageState: ... }).
 */
export function storageStateOf(username: string): string {
    return path.join(__dirname, '../../../var/e2e/auth', `${username}.json`);
}
