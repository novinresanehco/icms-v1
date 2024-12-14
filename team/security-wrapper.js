class UISecurityManager {
  constructor(config) {
    this.config = config;
    this.validators = new Map();
  }

  validateAccess(component, context = {}) {
    if (!this.validators.has(component)) {
      return false;
    }
    return this.validators.get(component)(context);
  }

  validateTemplate(template) {
    return this.validateAccess('template', { template });
  }

  registerValidator(component, validator) {
    this.validators.set(component, validator);
  }

  validateMediaAccess(mediaId) {
    return this.validateAccess('media', { id: mediaId });
  }
}

const securityConfig = {
  allowedTemplates: new Set(['content', 'gallery', 'page']),
  mediaTypes: new Set(['image', 'video']),
  maxGalleryItems: 50
};

export const security = new UISecurityManager(securityConfig);
