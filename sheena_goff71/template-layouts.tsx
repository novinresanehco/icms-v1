const DashboardLayout = ({ children, sidebar = true }) => {
  return (
    <div className="min-h-screen bg-gray-50">
      <header className="h-16 bg-white shadow-sm flex items-center px-6">
        <div className="flex-1 flex items-center justify-between">
          <div className="text-xl font-semibold">CMS Dashboard</div>
          <nav className="space-x-4">
            <Button>Content</Button>
            <Button>Media</Button>
            <Button>Templates</Button>
          </nav>
        </div>
      </header>

      <div className="flex h-[calc(100vh-4rem)]">
        {sidebar && (
          <aside className="w-64 border-r bg-white p-6">
            <nav className="space-y-2">
              <SidebarItem href="/content">Content Manager</SidebarItem>
              <SidebarItem href="/media">Media Gallery</SidebarItem>
              <SidebarItem href="/templates">Template Editor</SidebarItem>
            </nav>
          </aside>
        )}
        
        <main className="flex-1 p-6 overflow-auto">
          <div className="container mx-auto">
            {children}
          </div>
        </main>
      </div>
    </div>
  );
};

const ContentLayout = ({ content, media }) => {
  return (
    <div className="grid grid-cols-12 gap-6">
      <div className="col-span-8">
        <div className="bg-white rounded-lg shadow p-6">
          <div className="prose max-w-none" dangerouslySetInnerHTML={{ __html: content }} />
        </div>
      </div>
      
      <div className="col-span-4">
        <div className="bg-white rounded-lg shadow p-6">
          <MediaGallery items={media} />
        </div>
      </div>
    </div>
  );
};

const MediaGallery = ({ items }) => {
  return (
    <div className="grid grid-cols-2 gap-4">
      {items.map(item => (
        <div key={item.id} className="aspect-square rounded-lg overflow-hidden">
          <img 
            src={item.url}
            alt={item.title}
            className="w-full h-full object-cover hover:scale-105 transition-transform"
            loading="lazy"
          />
        </div>
      ))}
    </div>
  );
};

const SidebarItem = ({ href, children }) => (
  <a href={href} className="block px-4 py-2 rounded-lg hover:bg-gray-50">
    {children}
  </a>
);

const Button = ({ children, ...props }) => (
  <button 
    className="px-4 py-2 rounded-lg hover:bg-gray-50 transition-colors"
    {...props}
  >
    {children}
  </button>
);

export { DashboardLayout, ContentLayout, MediaGallery };
