/**
 * Template tag for GraphQL queries.
 * Provides syntax highlighting in editors and strips extra whitespace.
 */
export function gql(strings: TemplateStringsArray, ...values: unknown[]): string {
  return strings.reduce((result, str, i) => {
    return result + str + (values[i] ?? '');
  }, '').replace(/\s+/g, ' ').trim();
}
