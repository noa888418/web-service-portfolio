"""Check credential generation in an isolated directory, never print its contents."""
import json
from pathlib import Path
import tempfile
import unittest
from setup_demo import prepare


class DemoSetupTest(unittest.TestCase):
    def test_creation_and_no_overwrite(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            self.assertTrue(prepare(root))
            path = root / '.local' / 'credentials.json'
            before = path.read_bytes()
            accounts = json.loads(before)['accounts']
            self.assertEqual(len(accounts), 4)
            self.assertTrue(all(v['email'].endswith('@example.test') for v in accounts.values()))
            self.assertTrue(all(len(v['password']) == 43 for v in accounts.values()))
            self.assertEqual(len({v['password'] for v in accounts.values()}), 4)
            self.assertFalse(prepare(root))
            self.assertTrue(before == path.read_bytes(), 'Existing credentials were modified; values omitted.')


if __name__ == '__main__':
    unittest.main()
