class TemplateEngine {
  private templates = new Map();
  private securityRules = {
    allowedTags: ['div', 'p', 'h1', 'h2', 'h3', 'img', 'span'],
    allowedAttributes: ['class', 'id', 'src', 'alt', 'title'],
    maxDepth: 5
  };

  register(name: string, template: string): void {
    if (!this.validateTemplate(template)) throw new Error('Invalid template');
    this.templates.set(name, this.sanitizeTemplate(template));
  }

  render(name: string, data: any): string {
    const template = this.templates.get(name);
    if (!template) throw new Error('Template not found');
    return this.compile(template, this.validateData(data));
  }

  private validateTemplate(template: string): boolean {
    return (template.match(/<[^>]+>/g) || []).length <= this.securityRules.maxDepth;
  }

  private sanitizeTemplate(template: string): string {
    return template.replace(/<([^>]+)>/g, (match, p1) => {
      const tag = p1.split(' ')[0];
      return this.securityRules.allowedTags.includes(tag) ? match : '';
    });
  }

  private validateData(data: any): any {
    if (!data || typeof data !== 'object') throw new Error('Invalid data');
    return Object.fromEntries(
      Object.entries(data).map(([k, v]) => [k, this.sanitize(v)])
    );
  }

  private compile(template: string, data: any): string {
    return template.replace(/\{\{(.+?)\}\}/g, (_, key) => data[key] || '');
  }

  private sanitize(value: any): any {
    return typeof value === 'string' ? value.replace(/[<>]/g, '') : value;
  }
}

export default TemplateEngine;
