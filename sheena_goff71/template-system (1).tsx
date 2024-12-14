import React from 'react';
import { TemplateProvider, useTemplate, useTemplateDispatch } from './template-context';
import { Alert } from '@/components/ui/alert';
import { ChevronLeft, ChevronRight, X } from 'lucide-react';

const TemplateSystem = ({ initialLayout = 'default', initialContent = {}, media = [] }) => (
  <TemplateProvider>
    <TemplateManager 
      initialLayout={initialLayout}
      initialContent={initialContent}
      media={media}
    />
  </TemplateProvider>
);

const TemplateManager = ({ initialLayout, initialContent, media }) => {
  const dispatch = useTemplateDispatch();
  const { currentLayout, content } = useTemplate();

  const layouts = {
    default: { grid: 'grid-cols-1', zones: ['main'] },
    twoColumn: { grid: 'grid-cols-1 md:grid-cols-12', zones: ['main:8', 'sidebar:4'] },
    full: { grid: 'grid-cols-1', zones: ['header', 'main', 'footer'] }
  };

  return (
    <div className="template-system">
      <div className={`grid gap-6 ${layouts[currentLayout]?.grid || layouts.default.grid}`}>
        {layouts[currentLayout]?.zones.map(zone => {
          const [name, span] = zone.split(':');
          return (
            <div key={name} className={`${span ? `md:col-span-${span}` : ''}`}>
              <ContentZone name={name} content={content[name]} />
            </div>
          );
        })}
      </div>
      {media?.length > 0 && <MediaGallery items={media} />}
    </div>
  );
};

const ContentZone = ({ name, content }) => {
  if (!content) return null;

  return (
    <div className="prose max-w-none">
      {typeof content === 'string' ? (
        <div dangerouslySetInnerHTML={{ __html: content }} />
      ) : (
        content
      )}
    </div>
  );
};

const MediaGallery = ({ items = [] }) => {
  const [page, setPage] = React.useState(0);
  const itemsPerPage = 12;
  const pages = Math.ceil(items.length / itemsPerPage);
  const currentItems = items.slice(page * itemsPerPage, (page + 1) * itemsPerPage);

  return (
    <div className="space-y-6 mt-8">
      <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
        {currentItems.map((item, idx) => (
          <div key={idx} className="aspect-square">
            <img
              src={item.url || '/api/placeholder/400/320'}
              alt={item.alt || ''}
              className="w-full h-full object-cover rounded-lg"
            />
          </div>
        ))}
      </div>

      {pages > 1 && (
        <div className="flex justify-center items-center gap-4">
          <button
            onClick={() => setPage(p => Math.max(0, p - 1))}
            disabled={page === 0}
            className="p-2 disabled:opacity-50"
          >
            <ChevronLeft className="w-5 h-5" />
          </button>
          <span>Page {page + 1} of {pages}</span>
          <button
            onClick={() => setPage(p => Math.min(pages - 1, p + 1))}
            disabled={page === pages - 1}
            className="p-2 disabled:opacity-50"
          >
            <ChevronRight className="w-5 h-5" />
          </button>
        </div>
      )}
    </div>
  );
};

export default TemplateSystem;
