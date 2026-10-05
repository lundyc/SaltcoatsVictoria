"""Validate synthetic HTTP response samples against the checked-in OpenAPI schemas.

Usage: python3 mobile_api_contract_test.py /tmp/mch-api-samples.json
Requires the jsonschema Python package. The PHP integration suite creates samples
when run with --samples /tmp/mch-api-samples.json.
"""
import json
from pathlib import Path
import re
import sys
import warnings

warnings.filterwarnings('ignore', category=DeprecationWarning)
from jsonschema import Draft202012Validator, FormatChecker, RefResolver

spec = json.loads((Path(__file__).parent / '../lib/mobile_api/openapi.json').read_text())
samples = json.loads(Path(sys.argv[1]).read_text())
resolver = RefResolver.from_schema(spec)
for schema in spec['components']['schemas'].values():
    Draft202012Validator.check_schema(schema)

checked = 0
for sample in samples:
    matched = None
    for path, operations in spec['paths'].items():
        pattern = re.sub(r'\{[^}]+\}', '[^/]+', path)
        if re.fullmatch(pattern, sample['path']) and sample['method'] in operations:
            matched = operations[sample['method']]
            break
    if sample['status'] == 204:
        assert sample['body'] == [], '204 must have no JSON body'
        continue
    if matched:
        response = matched['responses'][str(sample['status'])]
        schema = response['content']['application/json']['schema']
    else:
        # Unmatched routes/methods still follow the common error contract.
        if sample['method'] == 'options':
            continue
        assert sample['status'] >= 400
        schema = spec['components']['schemas']['Error']
    validator = Draft202012Validator(schema, resolver=resolver, format_checker=FormatChecker())
    errors = list(validator.iter_errors(sample['body']))
    if errors:
        # Do not print the full response (it may contain synthetic bearer tokens).
        raise AssertionError(f"{sample['method']} {sample['path']} ({sample['status']}): " + '; '.join(e.message for e in errors))
    checked += 1
print(f'PASS {checked} response samples match the OpenAPI contract')
