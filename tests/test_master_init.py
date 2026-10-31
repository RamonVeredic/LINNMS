import unittest

from scripts.master import init


class InitConversionTests(unittest.TestCase):
    @staticmethod
    def source(header=""):
        return (
            header
            + 'set -eu\n'
            'SOURCE_ROOT=/workspace/linnms-source\n'
            'SOURCE_SEED=/opt/linnms/source-seed\n'
            'SOURCE_MOUNTS="${LINNMS_SOURCE_MOUNTS:-0}"\n'
            'if [ "$SOURCE_MOUNTS" = "1" ]; then\n'
            '  echo private-staging-logic\n'
            'else\n'
            '  CONFIG_TEMPLATE="$CONFIG_IMAGE"\n'
            '  NGINX_TEMPLATE="$NGINX_IMAGE"\n'
            'fi\n\n'
            'if [ -L /config/nginx/log ]; then\n'
            '  :\n'
            'fi\n\n'
            'if [ "$SOURCE_MOUNTS" = "1" ]; then\n'
            '  chown -R abc:users "$SOURCE_ROOT/config/www"\n'
            'fi\n\n'
            'echo ready\n'
        )

    def test_staging_without_shebang_converts_to_public_init(self):
        result = init(self.source())
        self.assertTrue(result.startswith('#!/usr/bin/with-contenv sh\nset -eu\n'))
        self.assertEqual(result.count('#!/usr/bin/with-contenv sh'), 1)
        self.assertIn('CONFIG_TEMPLATE="$CONFIG_IMAGE"', result)
        self.assertNotIn('SOURCE_ROOT', result)
        self.assertNotIn('SOURCE_MOUNTS', result)
        self.assertNotIn('staging', result.lower())

    def test_existing_shebang_is_not_duplicated(self):
        result = init(self.source('#!/usr/bin/with-contenv sh\n'))
        self.assertEqual(result.count('#!/usr/bin/with-contenv sh'), 1)

    def test_invalid_header_is_rejected(self):
        with self.assertRaisesRegex(ValueError, 'Invalid init script'):
            init('#invalid\n' + self.source())

    def test_unremoved_staging_logic_is_rejected(self):
        with self.assertRaisesRegex(ValueError, 'Staging-only init logic remains'):
            init(self.source() + 'echo "$SOURCE_ROOT"\n')


if __name__ == '__main__':
    unittest.main()
