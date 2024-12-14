import React, { useState, useEffect } from 'react';
import { Security } from './security';
import { Cache } from './cache';

interface TemplateProps {
  content: Content;
  layout: Layout;
  security: SecurityContext;
}

interface Content {
  id: string;
  data: any;
  media?: MediaAsset[];
}

interface Layout {
  id: string;
  sections: Section[];
}

interface SecurityContext {
  permissions: string[];
  userContext: UserContext;
}

export const TemplateSystem: React.FC<TemplateProps> = ({ 
  content,
  layout,
  security
}) => {
  // Critical state management with security validation
  const [validatedContent, setValidatedContent] = useState<Content | null>(null);
  const [securityChecks, setSecurityChecks] = useState<boolean>(false);

  useEffect(() => {
    // Critical security validation before rendering
    const validateAndSetContent = async () => {
      try {
        // Security validation
        const securityResult = await Security.validateContentAccess(
          content, 
          security
        );
        
        if (!securityResult.approved) {
          throw new Error('Security validation failed');
        }

        // Content validation with integrity check
        const validatedData = await validateContent(content);
        
        // Update state only after all validations pass
        setSecurityChecks(true);
        setValidatedContent(validatedData);

        // Cache validated content with security context
        Cache.store(`template:${content.id}`, {
          content: validatedData,
          security: security,
          timestamp: Date.now()
        });

      } catch (error) {
        console.error('Template security validation failed:', error);
        setSecurityChecks(false);
        setValidatedContent(null);
      }
    };

    validateAndSetContent();
  }, [content, security]);

  // Render only after security validation
  if (!securityChecks || !validatedContent) {
    return <div>Validating content...</div>;
  }

  return (
    <div className="template-container">
      {layout.sections.map((section, index) => (
        <TemplateSection
          key={`section-${index}`}
          section={section}
          content={validatedContent}
          security={security}
        />
      ))}
    </div>
  );
};

// Template section with security boundary
const TemplateSection: React.FC<{
  section: Section;
  content: Content;
  security: SecurityContext;
}> = ({ section, content, security }) => {
  // Section-level security check
  const canRenderSection = Security.checkSectionPermissions(
    section,
    security.permissions
  );

  if (!canRenderSection) {
    return null;
  }

  return (
    <div className="template-section">
      <ContentRenderer
        sectionId={section.id}
        content={content}
        security={security}
      />
      {section.media && (
        <MediaGallery
          assets={content.media}
          security={security}
        />
      )}
    </div>
  );
};

// Content renderer with XSS protection
const ContentRenderer: React.FC<{
  sectionId: string;
  content: Content;
  security: SecurityContext;
}> = ({ sectionId, content, security }) => {
  // Content sanitization and rendering logic
  const sanitizedContent = Security.sanitizeContent(content.data);

  return (
    <div 
      className="content-container"
      dangerouslySetInnerHTML={{ 
        __html: sanitizedContent 
      }}
    />
  );
};

// Media gallery with security checks
const MediaGallery: React.FC<{
  assets?: MediaAsset[];
  security: SecurityContext;
}> = ({ assets, security }) => {
  if (!assets?.length) return null;

  return (
    <div className="media-gallery">
      {assets.map(asset => (
        <MediaAsset
          key={asset.id}
          asset={asset}
          security={security}
        />
      ))}
    </div>
  );
};

// Protected media asset component
const MediaAsset: React.FC<{
  asset: MediaAsset;
  security: SecurityContext;
}> = ({ asset, security }) => {
  // Media access validation
  const canAccessMedia = Security.checkMediaAccess(
    asset,
    security.permissions
  );

  if (!canAccessMedia) {
    return null;
  }

  return (
    <div className="media-asset">
      <img 
        src={Security.getSecureUrl(asset.url)}
        alt={Security.sanitizeText(asset.alt)}
        className="w-full h-auto"
      />
    </div>
  );
};

// Content validation with integrity check
async function validateContent(content: Content): Promise<Content> {
  // Deep validation of content structure and data
  const validation = await Security.validateContentIntegrity(content);
  
  if (!validation.valid) {
    throw new Error('Content validation failed');
  }
  
  return validation.content;
}
